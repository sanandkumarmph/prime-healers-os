"""Offline SMTP diagnostics: fake clients only; no account, network or config writes."""
import base64
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import smtplib
import socket
import ssl
import subprocess
import sys
import tempfile
import types
import unittest
from unittest import mock

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('zoho_diagnostics_fixture',HERE/'setup-zoho-mail.py')
helper=importlib.util.module_from_spec(spec);spec.loader.exec_module(helper)
SECRET='PHOS_SYNTHETIC_DIAGNOSTIC_PASSWORD_42'
PRIVATE='SYNTHETIC_PRIVATE_SMTP_EXCEPTION_'+SECRET


class DiagnosticFixture(unittest.TestCase):
    def values(self):return helper.settings('smtp.zoho.com',465,SECRET,'Synthetic Fixture')
    def assert_private(self,text):
        self.assertTrue(SECRET not in text,'Sensitive fixture marker appeared in output.')
        self.assertTrue(PRIVATE not in text,'Private exception fixture text appeared in output.')
        self.assertTrue(base64.b64encode(SECRET.encode()).decode() not in text,'Encoded sensitive fixture marker appeared in output.')

    def driver(self,scenario='success',port=465):
        events=[]
        fake=types.ModuleType('smtplib')
        for name in ('SMTPException','SMTPAuthenticationError','SMTPConnectError','SMTPNotSupportedError',
                     'SMTPServerDisconnected','SMTPResponseException','SMTPHeloError'):
            setattr(fake,name,getattr(smtplib,name))
        testcase=self
        class Client:
            def __init__(self,verified):
                self.verified=verified
                self.esmtp_features={'auth':'LOGIN' if scenario=='plain_missing' else 'PLAIN'}
                self.sock=types.SimpleNamespace(version=lambda:'TLSv1.3' if self.verified else None)
            def ehlo(self):events.append('ehlo');return 250,b'fixture'
            def has_extn(self,name):
                testcase.assertEqual(name,'starttls');return scenario!='starttls_missing'
            def starttls(self,*,context):
                events.append('starttls');check_context(context)
                if scenario=='tls_certificate':raise ssl.SSLCertVerificationError(PRIVATE)
                if scenario=='tls_handshake':raise ssl.SSLError(PRIVATE)
                if scenario=='protocol':raise smtplib.SMTPNotSupportedError(PRIVATE)
                self.verified=True;return 220,b'fixture'
            def auth(self,mechanism,callback,*,initial_response_ok):
                events.append('auth')
                testcase.assertTrue(self.verified,'AUTH preceded verified TLS in the fake driver.')
                testcase.assertEqual((mechanism,initial_response_ok),('PLAIN',True))
                testcase.assertEqual(callback(),'\0support@primehealers.com\0'+SECRET)
                if scenario=='authentication':raise smtplib.SMTPAuthenticationError(535,PRIVATE.encode())
                return 235,b'fixture'
            def close(self):events.append('close')
        def check_context(context):
            self.assertIsInstance(context,ssl.SSLContext)
            self.assertTrue(context.check_hostname)
            self.assertEqual(context.verify_mode,ssl.CERT_REQUIRED)
            self.assertGreaterEqual(context.minimum_version,ssl.TLSVersion.TLSv1_2)
        def construct(host,actual_port,*,timeout,context=None):
            events.append('connect')
            self.assertEqual((host,actual_port,timeout),('smtp.zoho.com',port,20))
            if scenario=='dns':raise socket.gaierror(-2,PRIVATE)
            if scenario=='connect':raise ConnectionRefusedError(PRIVATE)
            if scenario=='closed':raise smtplib.SMTPServerDisconnected(PRIVATE)
            if scenario=='timeout':raise TimeoutError(PRIVATE)
            if scenario=='unexpected':raise RuntimeError(PRIVATE)
            verified=context is not None
            if verified:
                check_context(context)
                if scenario=='tls_certificate':raise ssl.SSLCertVerificationError(PRIVATE)
                if scenario=='tls_handshake':raise ssl.SSLError(PRIVATE)
            return Client(verified)
        fake.SMTP=fake.SMTP_SSL=construct
        stdout,stderr=io.StringIO(),io.StringIO();status=0
        with mock.patch.dict(sys.modules,{'smtplib':fake}),mock.patch.object(sys,'stdin',io.StringIO(json.dumps({
                'host':'smtp.zoho.com','port':port,'password':SECRET}))),contextlib.redirect_stdout(stdout),contextlib.redirect_stderr(stderr):
            try:exec(compile(helper.SMTP_AUTH,'<fake-smtp-diagnostic>','exec'),{'__name__':'offline_smtp_fixture'})
            except SystemExit as error:status=error.code
        self.assert_private(stdout.getvalue()+stderr.getvalue())
        result=json.loads(stdout.getvalue())
        return status,result,events

    def parent_result(self,code,payload):
        raw=payload if isinstance(payload,bytes) else json.dumps(payload).encode()
        process=types.SimpleNamespace(returncode=code,stdout=raw,stderr=PRIVATE.encode())
        stdout,stderr=io.StringIO(),io.StringIO();error=None
        with mock.patch.object(helper.subprocess,'run',return_value=process) as run,\
                contextlib.redirect_stdout(stdout),contextlib.redirect_stderr(stderr):
            try:helper.authenticate_once(self.values())
            except Exception as caught:error=caught
        self.assert_private(stdout.getvalue()+stderr.getvalue())
        if error is not None:self.assert_private(str(error))
        self.assertEqual(run.call_count,1)
        return error


class SmtpChildDiagnosticTests(DiagnosticFixture):
    def test_both_verified_tls_ports_authenticate_once_without_message_commands(self):
        for port in (465,587):
            with self.subTest(port=port):
                status,result,events=self.driver(port=port)
                self.assertEqual((status,result),(0,{'authenticated':True,'tls_version':'TLSv1.3'}))
                self.assertEqual((events.count('connect'),events.count('auth')),(1,1))
                self.assertEqual(events.count('starttls'),int(port==587))

    def test_failures_are_whitelisted_and_never_echo_private_exception_text(self):
        cases=[('dns',465,'DNS_LOOKUP_FAILED'),('connect',465,'SMTP_CONNECTION_FAILED'),
               ('closed',465,'SMTP_CONNECTION_CLOSED'),('timeout',465,'SMTP_TIMEOUT'),
               ('tls_certificate',465,'TLS_CERTIFICATE_REJECTED'),('tls_handshake',465,'TLS_HANDSHAKE_FAILED'),
               ('tls_certificate',587,'TLS_CERTIFICATE_REJECTED'),('starttls_missing',587,'STARTTLS_NOT_OFFERED'),
               ('plain_missing',465,'AUTH_MECHANISM_UNAVAILABLE'),('authentication',465,'SMTP_AUTHENTICATION_REJECTED'),
               ('protocol',587,'SMTP_PROTOCOL_UNSUPPORTED'),('unexpected',465,'SMTP_UNEXPECTED_FAILURE')]
        for scenario,port,reason in cases:
            with self.subTest(scenario=scenario,port=port):
                status,result,events=self.driver(scenario,port)
                self.assertEqual(status,2)
                self.assertEqual(set(result),{'authenticated','error_class','phase','reason'})
                self.assertIs(result['authenticated'],False)
                self.assertEqual(result['reason'],reason)
                self.assertIn(result['phase'],helper.SMTP_PHASES)
                self.assertEqual(events.count('connect'),1)
                self.assertEqual(events.count('auth'),int(scenario=='authentication'))


class ParentSmtpRoutingTests(DiagnosticFixture):
    def test_execute_default_strict_zero_and_auth_opt_in_zero_or_two(self):
        for status in (0,2):
            process=types.SimpleNamespace(returncode=status,stdout=b'{}',stderr=PRIVATE.encode())
            with mock.patch.object(helper.subprocess,'run',return_value=process):
                if status==0:self.assertEqual(helper.execute(['synthetic']),b'{}')
                else:
                    with self.assertRaises(helper.Refused):helper.execute(['synthetic'])
                self.assertEqual(helper.execute(['synthetic'],allow_failure=True),(status,b'{}'))

    def test_success_status_zero_routes_without_exposing_stderr(self):
        self.assertIsNone(self.parent_result(0,{'authenticated':True,'tls_version':'TLSv1.3'}))

    def test_failure_status_two_routes_to_static_reason_and_phase(self):
        error=self.parent_result(2,{'authenticated':False,'error_class':'SMTPAuthenticationError',
            'phase':'authenticate','reason':'SMTP_AUTHENTICATION_REJECTED'})
        self.assertIsInstance(error,helper.DiagnosticRefused)
        self.assertEqual(error.reason,'SMTP_AUTHENTICATION_REJECTED')
        self.assertEqual(error.smtp_phase,'authenticate')

    def test_bad_json_fields_phase_reason_and_status_are_rejected_without_reflection(self):
        base={'authenticated':False,'error_class':'SMTPAuthenticationError','phase':'authenticate',
              'reason':'SMTP_AUTHENTICATION_REJECTED'}
        cases=[(2,PRIVATE.encode()),(2,{**base,'password':SECRET}),(2,{**base,'phase':PRIVATE}),
               (2,{**base,'reason':PRIVATE}),(2,{**base,'error_class':PRIVATE}),
               (0,{'authenticated':True,'tls_version':'TLSv1.3','password':SECRET}),
               (2,{'authenticated':True,'tls_version':'TLSv1.3'})]
        for status,payload in cases:
            with self.subTest(status=status,payload_type=type(payload).__name__):
                error=self.parent_result(status,payload)
                self.assertIsInstance(error,helper.DiagnosticRefused)
                self.assertEqual(error.reason,'SMTP_RESULT_INVALID')

    def test_unexpected_child_exit_and_unverified_tls_have_static_categories(self):
        error=self.parent_result(7,PRIVATE.encode())
        self.assertIsInstance(error,helper.DiagnosticRefused);self.assertEqual(error.reason,'SMTP_PROCESS_FAILED')
        error=self.parent_result(0,{'authenticated':True,'tls_version':'TLSv1.1'})
        self.assertIsInstance(error,helper.DiagnosticRefused);self.assertEqual(error.reason,'TLS_NOT_VERIFIED')

    def test_process_timeout_does_not_reflect_private_command_or_captured_output(self):
        expired=subprocess.TimeoutExpired([PRIVATE],60,output=PRIVATE.encode(),stderr=PRIVATE.encode())
        stdout,stderr=io.StringIO(),io.StringIO()
        with mock.patch.object(helper.subprocess,'run',side_effect=expired),\
                contextlib.redirect_stdout(stdout),contextlib.redirect_stderr(stderr):
            with self.assertRaises(helper.DiagnosticRefused) as caught:helper.authenticate_once(self.values())
        self.assertEqual(caught.exception.reason,'SMTP_TIMEOUT')
        self.assert_private(stdout.getvalue()+stderr.getvalue()+str(caught.exception))


class PreauthenticationSafetyTests(DiagnosticFixture):
    def test_invalid_operator_values_have_static_input_categories_before_any_effect(self):
        cases=[(('smtp.zoho.com\n'+PRIVATE,465,SECRET,'Fixture'),'INPUT_HOST_INVALID'),
               (('unsupported.invalid',465,SECRET,'Fixture'),'INPUT_HOST_UNSUPPORTED'),
               (('smtp.zoho.com',2525,SECRET,'Fixture'),'INPUT_PORT_INVALID'),
               (('smtp.zoho.com',465,' '+SECRET,'Fixture'),'INPUT_PASSWORD_INVALID'),
               (('smtp.zoho.com',465,SECRET,'Fixture\n'+PRIVATE),'INPUT_NAME_INVALID')]
        for values,reason in cases:
            with self.subTest(reason=reason),mock.patch.object(helper,'authenticate_once') as auth,\
                 mock.patch.object(helper,'execute') as execute,mock.patch.object(helper,'atomic') as atomic,\
                 mock.patch.object(helper,'artisan') as artisan,contextlib.redirect_stdout(io.StringIO()) as output:
                with self.assertRaises(helper.DiagnosticRefused) as caught:helper.settings(*values)
                self.assertEqual(caught.exception.reason,reason)
                self.assert_private(output.getvalue()+str(caught.exception))
                for operation in (auth,execute,atomic,artisan):operation.assert_not_called()

    def test_candidate_or_web_preflight_failure_cannot_authenticate_or_mutate_files(self):
        for scenario in ('existing','candidate','web'):
            with self.subTest(scenario=scenario),tempfile.TemporaryDirectory(prefix='zoho-diagnostic-offline-',dir=HERE) as folder:
                app=Path(folder);(app/'bootstrap/cache').mkdir(parents=True);(app/'storage/framework').mkdir(parents=True)
                env=app/'.env';cache=app/'bootstrap/cache/config.php'
                env.write_bytes(b'APP_ENV=uat\nMAIL_PASSWORD="synthetic-original"\n');cache.write_bytes(b'<?php /* synthetic */')
                before=(env.read_bytes(),cache.read_bytes(),env.stat().st_mtime_ns,cache.stat().st_mtime_ns)
                config={'identity_verified':scenario!='existing','providers_loaded':0}
                inspections=[config,{'candidate_valid':scenario!='candidate'}]
                stdout,stderr=io.StringIO(),io.StringIO()
                with mock.patch.object(helper,'APP',app),mock.patch.object(helper,'inspect_configuration',side_effect=inspections),\
                     mock.patch.object(helper,'web_cache',side_effect=helper.Refused() if scenario=='web' else None),\
                     mock.patch.object(helper,'authenticate_once') as auth,mock.patch.object(helper,'make_backup') as backup,\
                     mock.patch.object(helper,'atomic') as atomic,mock.patch.object(helper,'artisan') as artisan,\
                     mock.patch.object(helper,'ensure_maintenance') as maintenance,mock.patch.object(helper,'recover') as recover,\
                     contextlib.redirect_stdout(stdout),contextlib.redirect_stderr(stderr):
                    with self.assertRaises(helper.Refused):helper.configure(self.values())
                for operation in (auth,backup,atomic,artisan,maintenance,recover):operation.assert_not_called()
                self.assertEqual(before,(env.read_bytes(),cache.read_bytes(),env.stat().st_mtime_ns,cache.stat().st_mtime_ns))
                self.assertNotIn('SMTP_AUTHENTICATED=true',stdout.getvalue())
                self.assert_private(stdout.getvalue()+stderr.getvalue())

    def test_authentication_rejection_precedes_backup_environment_and_cache_activation(self):
        with tempfile.TemporaryDirectory(prefix='zoho-diagnostic-offline-',dir=HERE) as folder:
            app=Path(folder);(app/'bootstrap/cache').mkdir(parents=True);(app/'storage/framework').mkdir(parents=True)
            env=app/'.env';cache=app/'bootstrap/cache/config.php';env.write_bytes(b'APP_ENV=uat\n');cache.write_bytes(b'old-cache')
            before=(env.read_bytes(),cache.read_bytes())
            error=helper.DiagnosticRefused('SMTP_AUTHENTICATION_REJECTED',smtp_phase='authenticate')
            with mock.patch.object(helper,'APP',app),mock.patch.object(helper,'inspect_configuration',side_effect=[
                    {'identity_verified':True,'providers_loaded':0},{'candidate_valid':True}]),\
                 mock.patch.object(helper,'web_cache'),mock.patch.object(helper,'authenticate_once',side_effect=error) as auth,\
                 mock.patch.object(helper,'make_backup') as backup,mock.patch.object(helper,'atomic') as atomic,\
                 mock.patch.object(helper,'artisan') as artisan,mock.patch.object(helper,'ensure_maintenance') as maintenance:
                with self.assertRaises(helper.DiagnosticRefused):helper.configure(self.values())
            auth.assert_called_once()
            for operation in (backup,atomic,artisan,maintenance):operation.assert_not_called()
            self.assertEqual(before,(env.read_bytes(),cache.read_bytes()))

    def test_private_preflight_exception_message_is_not_printed_by_main(self):
        stdout,stderr=io.StringIO(),io.StringIO()
        with mock.patch.object(sys,'argv',['setup-zoho-mail.py']),\
             mock.patch.object(helper,'load_target',side_effect=RuntimeError(PRIVATE)),\
             mock.patch.object(helper,'authenticate_once') as auth,mock.patch.object(helper,'atomic') as atomic,\
             mock.patch.object(helper,'artisan') as artisan,contextlib.redirect_stdout(stdout),contextlib.redirect_stderr(stderr):
            result=helper.main()
        self.assertEqual(result,2)
        auth.assert_not_called();atomic.assert_not_called();artisan.assert_not_called()
        self.assert_private(stdout.getvalue()+stderr.getvalue())


if __name__=='__main__':unittest.main()

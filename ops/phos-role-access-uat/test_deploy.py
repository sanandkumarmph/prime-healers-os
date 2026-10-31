"""Offline tests only; no account POST, SMTP, DB, network, or live APP."""
import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest.mock import patch
import zipfile

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('password_reset_private_helper',HERE/'deploy.py')
d=importlib.util.module_from_spec(spec);spec.loader.exec_module(d)


class Fixture(unittest.TestCase):
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory(prefix='password-reset-offline-',dir=HERE)
        self.root=Path(self.temporary.name)/'app';self.root.mkdir()
        self.backup=Path(self.temporary.name)/'backup';self.backup.mkdir()
        self.uid=os.getuid() if hasattr(os,'getuid') else 0
        self.gid=os.getgid() if hasattr(os,'getgid') else 0
    def tearDown(self):self.temporary.cleanup()
    def put(self,name,data):
        path=self.root/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data);return path
    def package(self):
        folder=Path(self.temporary.name)/'package';folder.mkdir()
        entries=[]
        with zipfile.ZipFile(folder/'payload.zip','w',compression=zipfile.ZIP_STORED) as archive:
            for name,before in sorted(d.BASELINE_HASHES.items()):
                data=('<?php /* synthetic '+name+' */\n').encode()
                entry={'path':name,'baseline_sha256':before,'sha256':d.digest(data),'size':len(data)};entries.append(entry)
                item=zipfile.ZipInfo(name,(1980,1,1,0,0,0));item.create_system=3;item.external_attr=(stat.S_IFREG|0o644)<<16
                archive.writestr(item,data)
        manifest={'format':1,'environment':'PHOS_UAT','baseline':d.BASELINE,'release':'a'*40,
                  'archive_sha256':d.digest((folder/'payload.zip').read_bytes()),'files':entries}
        raw=d.json_bytes(manifest);(folder/'manifest.json').write_bytes(raw)
        return folder,manifest,{'PACKAGE_RELEASE':'a'*40,'PACKAGE_MANIFEST_SHA':d.digest(raw),
                               'PACKAGE_ARCHIVE_SHA':manifest['archive_sha256']}


class PackageTests(Fixture):
    def test_review_boundary_and_exact_role_paths(self):
        self.assertEqual(d.PACKAGE_RELEASE,'b62d46ac10cfbc620a102bf7578a92b2f9ccac7f')
        self.assertEqual(len(d.BASELINE_HASHES),25)
        self.assertEqual(sum(value is None for value in d.BASELINE_HASHES.values()),3)
        with patch.object(d,'PACKAGE_RELEASE',None),self.assertRaises(d.Refused):d.load_package(self.root)
        with patch.object(d,'ACTIVATION_REVIEW_COMPLETE',False):
            self.assertFalse(d.ACTIVATION_REVIEW_COMPLETE)
            with self.assertRaises(d.Refused):d.activate({},self.backup)
    def test_canonical_synthetic_package_accepts_exact_allowlist(self):
        folder,manifest,pins=self.package()
        with patch.multiple(d,**pins):
            _,payload=d.load_package(folder)
        self.assertEqual(set(payload),set(d.BASELINE_HASHES))
    def test_package_tampering_is_refused(self):
        folder,manifest,pins=self.package();(folder/'manifest.json').write_bytes((folder/'manifest.json').read_bytes()+b' ')
        with patch.multiple(d,**pins),self.assertRaises(d.Refused):d.load_package(folder)
    def test_wrong_before_hash_refused_even_with_repinned_manifest(self):
        folder,manifest,pins=self.package();manifest['files'][0]['baseline_sha256']='b'*64
        raw=d.json_bytes(manifest);(folder/'manifest.json').write_bytes(raw);pins['PACKAGE_MANIFEST_SHA']=d.digest(raw)
        with patch.multiple(d,**pins),self.assertRaises(d.Refused):d.load_package(folder)
    def test_dependency_or_extra_source_refused_even_with_repinned_manifest(self):
        folder,manifest,pins=self.package();manifest['files'][0]['path']='vendor/unsafe.php'
        raw=d.json_bytes(manifest);(folder/'manifest.json').write_bytes(raw);pins['PACKAGE_MANIFEST_SHA']=d.digest(raw)
        with patch.multiple(d,**pins),self.assertRaises(d.Refused):d.load_package(folder)
    def test_source_drift_and_new_file_collision_refused(self):
        sources={'app/Old.php':d.digest(b'old'),'app/Services/New.php':None}
        self.put('app/Old.php',b'old').chmod(0o444);(self.root/'app/Services').mkdir()
        with patch.object(d,'BASELINE_HASHES',sources):
            d.verify_source(self.root)
            self.put('app/Services/New.php',b'collision')
            with self.assertRaises(d.Refused):d.verify_source(self.root)
    def test_hash_correct_unrecoverable_source_metadata_refused_before_mutation(self):
        path=self.put('app/Old.php',b'old');(self.root/'app/Services').mkdir()
        original=d.signature(path)
        for changes in ({'mode':0o664},{'uid':999},{'gid':999}):
            bad={**original,**changes}
            with self.subTest(changes=changes),patch.object(d,'BASELINE_HASHES',{'app/Old.php':d.digest(b'old')}),\
                    patch.object(d,'signature',return_value=bad),self.assertRaisesRegex(d.Refused,'metadata'):
                d.verify_source(self.root)


class RecoveryTests(Fixture):
    def test_activated_sources_are_not_also_marked_as_immutable_preserved_files(self):
        seen=[]
        def record(path):
            seen.append(str(path.relative_to(self.root)).replace('\\','/'))
            return None
        with patch.object(d,'signature',side_effect=record),patch.object(d,'directory_identity',return_value={}):
            d.immutable_snapshot(self.root)
        self.assertEqual(set(seen)&set(d.BASELINE_HASHES),set())
        self.assertIn('.env',seen)
        self.assertIn('config/mail.php',seen)

    def test_atomic_mail_cache_restoration_accepts_exact_bytes_metadata_and_mtime(self):
        path=self.put('bootstrap/cache/config.php',b'baseline cache')
        files=d.FileRecovery(self.root,self.backup,['bootstrap/cache/config.php']);files.snapshot()
        files.install('bootstrap/cache/config.php',b'activated cache',self.uid,self.gid,0o600,lambda:None)
        activated=files.entries['bootstrap/cache/config.php']['after']
        d.atomic(path,b'activated cache',self.uid,self.gid,0o600)
        os.utime(path,ns=(activated['mtime_ns'],activated['mtime_ns']))
        self.assertTrue(d.restored_signature_matches(d.signature(path),activated))
        self.assertEqual(files.restore_files(),[])
        self.assertEqual(path.read_bytes(),b'baseline cache')

    def test_only_preserved_environment_file_identity_may_change_and_real_drift_is_rejected(self):
        original={'sha256':'a'*64,'size':12,'uid':5006,'gid':5006,'mode':0o600,
                  'device':1,'inode':10,'mtime_ns':123,'ctime_ns':456}
        expected={'.env':original,'composer.lock':original}
        restored={**original,'inode':11,'ctime_ns':789}
        with patch.object(d,'immutable_snapshot',return_value={'.env':restored,'composer.lock':original}):
            self.assertTrue(d.preserved_restoration_matches(self.root,expected))
        for key,value in [('sha256','b'*64),('size',13),('uid',99),('gid',99),('mode',0o644),('mtime_ns',124),('device',2)]:
            with self.subTest(key=key),patch.object(d,'immutable_snapshot',return_value={'.env':{**restored,key:value},'composer.lock':original}):
                self.assertFalse(d.preserved_restoration_matches(self.root,expected))
        with patch.object(d,'immutable_snapshot',return_value={'.env':restored,'composer.lock':restored}):
            self.assertFalse(d.preserved_restoration_matches(self.root,expected))

    def test_actual_source_new_file_undo_preserves_unrelated(self):
        self.put('app/Old.php',b'old');self.put('app/unrelated.php',b'unrelated')
        files=d.FileRecovery(self.root,self.backup,['app/Old.php','app/New.php']);files.snapshot()
        for name in files.names:files.install(name,b'new',self.uid,self.gid,0o644,lambda:None)
        self.assertEqual(files.restore_files(),[])
        self.assertEqual((self.root/'app/Old.php').read_bytes(),b'old')
        self.assertFalse((self.root/'app/New.php').exists())
        self.assertEqual((self.root/'app/unrelated.php').read_bytes(),b'unrelated')
    def test_foreign_missing_and_present_source_are_preserved(self):
        for name in ('app/A.php','app/B.php'):self.put(name,b'old')
        files=d.FileRecovery(self.root,self.backup,['app/A.php','app/B.php']);files.snapshot()
        for name in files.names:files.install(name,b'new',self.uid,self.gid,0o644,lambda:None)
        (self.root/'app/A.php').unlink();(self.root/'app/B.php').write_bytes(b'foreign')
        self.assertEqual(set(files.restore_files()),{'app/A.php','app/B.php'})
        self.assertFalse((self.root/'app/A.php').exists())
        self.assertEqual((self.root/'app/B.php').read_bytes(),b'foreign')
    def test_runtime_and_maintenance_failures_do_not_block_known_source_undo(self):
        self.put('app/A.php',b'old');files=d.FileRecovery(self.root,self.backup,['app/A.php']);files.snapshot()
        files.install('app/A.php',b'new',self.uid,self.gid,0o644,lambda:None)
        class Directories:
            def restore(self):return []
        def fail():raise RuntimeError('synthetic marker failure')
        result=d.recover_with_maintenance(files,Directories(),fail,fail,fail)
        self.assertEqual((self.root/'app/A.php').read_bytes(),b'old')
        self.assertFalse(result['complete']);self.assertIsNone(result['maintenance_retained'])
    def test_no_destination_directories_are_created_or_removed(self):
        (self.root/'app/Services').mkdir(parents=True)
        sentinel=self.put('app/Services/foreign.txt',b'keep')
        parent=d.NewAuthParent(self.root);parent.create(lambda:None)
        self.assertEqual(parent.restore(),[])
        self.assertEqual(sentinel.read_bytes(),b'keep')
        parent.entry['started']=True
        with self.assertRaises(d.Refused):parent.restore()
    def test_compiled_view_recovery_rejects_dependency_directories(self):
        recovery=d.CompiledViewRecovery(self.root,self.backup)
        for name in ('vendor','node_modules'):
            with self.subTest(name=name),self.assertRaises(d.Refused):recovery.live_path(name)
    def test_maintenance_intent_precedes_creation_and_post_capture_failure_is_owned(self):
        (self.root/'storage/framework').mkdir(parents=True)
        state={};events=[];real_atomic=d.atomic
        def failing_capture(path,*args):
            if path==self.backup/'maintenance.json':raise OSError('synthetic private capture failure')
            self.assertIn('maintenance_plan',state)
            return real_atomic(path,*args)
        with patch.object(d,'atomic',side_effect=failing_capture),self.assertRaises(OSError):
            d.maintenance_enter(self.root,self.backup,state,lambda:events.append(dict(state)))
        marker=self.root/'storage/framework/down'
        self.assertTrue(events)
        self.assertNotIn('maintenance_marker',events[0])
        self.assertEqual(state['maintenance_plan']['sha256'],d.digest(marker.read_bytes()))
        self.assertEqual(d.signature(marker),state['maintenance_marker'])
        d.maintenance_leave(self.root,state['maintenance_marker'])
        self.assertFalse(marker.exists())


class SafetyContractTests(unittest.TestCase):
    def test_no_dependency_pdf_migration_mail_or_account_post_commands(self):
        source=(HERE/'deploy.py').read_text()
        for value in ('npm ci','composer install','PDF_NODE_BINARY','PDF_BROWSER_PATH','migrate --','key:generate',
                      'smtplib','sendResetLink(','Password::reset(','opcache_reset(','--insecure','ignoreHttpsErrors'):
            self.assertNotIn(value,source)
        self.assertIn("(config('session.connection')?:$default)!==$default",source)
        self.assertIn("$config->get('session.table')!=='sessions'",source)
        self.assertIn('information_schema.tables',source)
        self.assertNotIn('SELECT * FROM users',source)
        self.assertNotIn('SELECT * FROM password_reset_tokens',source)
    def test_smtp_guard_instantiates_options_without_sending(self):
        self.assertIn("$safe['host']='smtp.invalid'",d.RUNTIME_GUARD_PHP)
        self.assertIn('createSymfonyTransport($safe)',d.RUNTIME_GUARD_PHP)
        self.assertIn("$safe['username']=null;$safe['password']=null",d.RUNTIME_GUARD_PHP)
        self.assertNotIn('->send(',d.RUNTIME_GUARD_PHP)
        self.assertNotIn('->start(',d.RUNTIME_GUARD_PHP)
    def test_health_accepts_only_dedicated_privacy_headers_and_uses_no_post(self):
        seen=[]
        def http(path,*args,**kwargs):
            seen.append(path)
            if path in ('/dashboard','/rentals','/customers'):
                return '302',b'','Location: https://'+d.HOST+'/login\r\n'
            return '200',b'','Cache-Control: private, no-store\r\nReferrer-Policy: no-referrer\r\n'
        with patch.object(d,'http_check',side_effect=http):d.health()
        self.assertIn('/forgot-password',seen)
    def test_both_mutation_modes_use_shared_reviewed_locks(self):
        source=(HERE/'deploy.py').read_text()
        self.assertEqual(tuple(path.as_posix() for path in d.LOCK_PATHS),('/root/phos-tabbed-dashboard-deploy.lock','/root/phos-zoho-mail-uat.lock'))
        self.assertGreaterEqual(source.count('with mutation_lock()'),2)


if __name__=='__main__':unittest.main()

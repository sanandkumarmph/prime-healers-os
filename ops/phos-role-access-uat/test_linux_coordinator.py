"""Synthetic disposable Linux/root fixtures; no APP, PHP, mail, DB or network."""
from contextlib import ExitStack
import importlib.util
import os
from pathlib import Path
import signal
import stat
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('isolated_password_reset_linux',HERE/'deploy.py')
d=importlib.util.module_from_spec(spec);spec.loader.exec_module(d)
REAL_APP=d.APP
DEFAULT_ACTIVATION=d.ACTIVATION_REVIEW_COMPLETE
ROOT_LINUX=sys.platform.startswith('linux') and hasattr(os,'geteuid') and os.geteuid()==0


@unittest.skipUnless(ROOT_LINUX,'Disposable Linux/root only; Windows skip is not acceptance.')
class NarrowCoordinatorLinux(unittest.TestCase):
    def setUp(self):
        self.assertIs(d.ACTIVATION_REVIEW_COMPLETE,DEFAULT_ACTIVATION)
        self.temporary=tempfile.TemporaryDirectory(prefix='phos-reset-linux-fixture-')
        self.boundary=Path(self.temporary.name).resolve();os.chmod(self.boundary,0o711)
        self.assertFalse(self.boundary.is_relative_to(REAL_APP))
        self.app=self.boundary/'synthetic-app';self.mkdir(self.app,5006,0o750)
        self.backup=self.boundary/'private-backup';self.mkdir(self.backup,0,0o700)
        dirs=['app','app/Http','app/Http/Controllers','app/Http/Controllers/Auth','app/Http/Middleware','app/Services',
              'app/Providers','routes','resources','resources/views','resources/views/auth','config','bootstrap',
              'bootstrap/cache','storage','storage/framework','storage/framework/views','storage/logs','public',
              'public/build','public/css','public/js','vendor','vendor/composer','node_modules']
        for name in dirs:self.mkdir(self.app/name,5006,0o700 if name.startswith(('storage','bootstrap/cache')) else 0o755)
        for name in sorted(d.BASELINE_HASHES):
            for parent in reversed((self.app/name).parent.parents):
                if parent.is_relative_to(self.app) and not parent.exists():self.mkdir(parent,5006,0o755)
            parent=(self.app/name).parent
            if not parent.exists():self.mkdir(parent,5006,0o755)
        self.baseline,self.payload,self.original={},{},{}
        for name,old in d.BASELINE_HASHES.items():
            data=('<?php /* new synthetic '+name+' */\n').encode();self.payload[name]=data
            if old is not None:
                before=('<?php /* old synthetic '+name+' */\n').encode();self.write(self.app/name,before,0,0o644)
                self.original[name]=before;self.baseline[name]=d.digest(before)
            else:self.baseline[name]=None
        self.first=next(iter(self.original))
        self.env=b'APP_ENV=uat\nMAIL_PASSWORD="synthetic-not-a-secret"\n'
        self.write(self.app/'.env',self.env,5006,0o600)
        for name in ('composer.json','composer.lock','package.json','package-lock.json','vendor/autoload.php',
                     'vendor/composer/installed.php','vendor/composer/installed.json','bootstrap/cache/packages.php',
                     'bootstrap/cache/services.php','public/build/old.js','public/css/old.css','public/js/old.js'):
            self.write(self.app/name,('synthetic-preserved-'+name).encode(),5006,0o600 if name.startswith('bootstrap/cache') else 0o644)
        for name in d.CACHE_NAMES:self.write(self.app/'bootstrap/cache'/name,('old-'+name).encode(),5006,0o600)
        self.write(self.app/'storage/framework/views/old.php',b'old-compiled-view',5006,0o600)
        self.original_caches={name:(self.app/'bootstrap/cache'/name).read_bytes() for name in d.CACHE_NAMES}
        self.original_views=d.tree_signature(self.app/'storage/framework/views')
        self.preserved=d.immutable_snapshot(self.app)
        self.fault=None;self.fired=False;self.events=[]
        self.context=ExitStack()
        self.context.enter_context(patch.multiple(d,APP=self.app,BASELINE_HASHES=self.baseline,
            ACTIVATION_REVIEW_COMPLETE=True,LOG_ROOT=self.backup,php_configuration=lambda:{'identity':True},
            web_php_probe=self.web,runtime_guards=lambda:None,http_check=self.http,run=self.artisan))
        self.addCleanup(self.cleanup)

    def cleanup(self):
        self.context.close();self.assertIs(d.ACTIVATION_REVIEW_COMPLETE,DEFAULT_ACTIVATION);self.assertEqual(d.APP,REAL_APP)
        self.assertEqual(Path(self.temporary.name).resolve(),self.boundary)
        self.assertTrue(self.boundary.name.startswith('phos-reset-linux-fixture-'))
        self.assertFalse(self.boundary.is_relative_to(REAL_APP));self.temporary.cleanup()
    def mkdir(self,path,uid,mode):
        path.mkdir();os.chown(path,uid,uid);os.chmod(path,mode)
    def write(self,path,data,uid,mode):
        self.assertTrue(path.is_relative_to(self.boundary));d.atomic(path,data,uid,uid,mode)
    def owner_write(self,path,data):
        self.assertTrue(path.is_relative_to(self.app));d.no_links(path)
        pid=os.fork()
        if pid==0:
            try:
                os.setgroups([]);os.setgid(5006);os.setuid(5006);os.umask(0o077)
                descriptor=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_TRUNC|os.O_NOFOLLOW,0o600)
                with os.fdopen(descriptor,'wb') as output:output.write(data)
                os.chmod(path,0o600);os._exit(0)
            except BaseException:os._exit(91)
        deadline=time.monotonic()+10;status=None
        while time.monotonic()<deadline:
            ended,value=os.waitpid(pid,os.WNOHANG)
            if ended:status=value;break
            time.sleep(0.02)
        if status is None:os.kill(pid,signal.SIGKILL);os.waitpid(pid,0)
        self.assertIsNotNone(status);self.assertTrue(os.WIFEXITED(status) and os.WEXITSTATUS(status)==0)
        info=path.stat();self.assertEqual((info.st_uid,info.st_gid,stat.S_IMODE(info.st_mode)),(5006,5006,0o600))
    def artisan(self,arguments,*,cwd,environment,**unused):
        self.assertEqual(cwd,self.app);self.assertEqual(str(arguments[0]),str(d.PHP))
        command=arguments[list(arguments).index('artisan')+1]
        if command=='config:cache':path=Path(environment['APP_CONFIG_CACHE'])
        elif command=='route:cache':path=Path(environment['APP_ROUTES_CACHE'])
        else:
            self.assertEqual(command,'view:cache');path=Path(environment['VIEW_COMPILED_PATH'])/'new.php'
        self.owner_write(path,('new-'+command).encode());return b''
    def web(self,backup,invalidate):
        self.assertEqual(backup,self.backup);self.events.append(('web',invalidate,(self.app/self.first).read_bytes()))
        if self.fault=='runtime' and invalidate:raise RuntimeError('synthetic persistent web failure')
        return {'ok':True}
    def http(self,path,backup,*,local,header=None):
        self.assertEqual(backup,self.backup)
        if self.fault=='foreign' and path=='/forgot-password' and not self.fired:
            self.fired=True
            self.owner_write(self.app/self.first,b'foreign-controller')
            self.owner_write(self.app/'bootstrap/cache/config.php',b'foreign-cache')
            self.owner_write(self.app/'.env',b'foreign-synthetic-env')
            self.owner_write(self.app/d.NEW_PARENT/'foreign.txt',b'foreign-auth-child')
            raise RuntimeError('synthetic failed online check with foreign drift')
        if path in ('/dashboard','/rentals','/customers'):
            return '302',b'','Location: https://'+d.HOST+'/login\r\n'
        self.assertIn(path,('/login','/up','/forgot-password','/reset-password/phos-nonexistent-reset-probe'))
        # Dedicated reset middleware does not depend on a global nosniff header.
        return '200',b'fixture','Cache-Control: private, no-store\r\nReferrer-Policy: no-referrer\r\n'
    def invoke(self):
        self.assertNotEqual(self.app,REAL_APP);d.activate(self.payload,self.backup)
    def assert_original(self,allow_mail_restoration=False):
        for name,value in self.original.items():
            self.assertEqual((self.app/name).read_bytes(),value)
            info=(self.app/name).stat();self.assertEqual((info.st_uid,info.st_gid,stat.S_IMODE(info.st_mode)),(0,0,0o644))
        for name,old in self.baseline.items():
            if old is None:self.assertFalse((self.app/name).exists())
        self.assertTrue((self.app/d.NEW_PARENT).is_dir())
        for name,value in self.original_caches.items():self.assertEqual((self.app/'bootstrap/cache'/name).read_bytes(),value)
        self.assertEqual(d.tree_signature(self.app/'storage/framework/views'),self.original_views)
        if allow_mail_restoration:
            self.assertTrue(d.preserved_restoration_matches(self.app,self.preserved))
        else:
            self.assertEqual(d.immutable_snapshot(self.app),self.preserved)

    def test_real_atomic_mail_restore_then_code_cache_and_view_recovery_leaves_uat_online(self):
        self.invoke()
        state=d.parse_json((self.backup/'recovery-state.json').read_bytes())
        mail_spec=importlib.util.spec_from_file_location('mail_restore_fixture',HERE.parent/'phos-password-recovery-uat/setup-zoho-mail.py')
        mail=importlib.util.module_from_spec(mail_spec);mail_spec.loader.exec_module(mail)
        mail_backup=self.boundary/'mail-backup';self.mkdir(mail_backup,0,0o700)
        entries={}
        for label,name in [('environment','.env'),('config-cache','bootstrap/cache/config.php')]:
            entry=mail.snapshot(self.app/name)
            entries[label]={key:value for key,value in entry.items() if key!='data'}
            mail.atomic(mail_backup/(label+'.before'),entry['data'],0,0,0o600)
        env_before=d.signature(self.app/'.env')
        cache_before=d.signature(self.app/'bootstrap/cache/config.php')
        mail.atomic(self.app/'.env',b'APP_ENV=uat\nMAIL_PASSWORD="synthetic-mail-change"\n',5006,5006,0o600)
        mail.atomic(self.app/'bootstrap/cache/config.php',b'synthetic mail cache',5006,5006,0o600)
        mail_state={'files':entries,'cache_mutation_started':True,
            'new_environment_signature':mail.file_signature(mail.snapshot(self.app/'.env')),
            'new_cache_signature':mail.file_signature(mail.snapshot(self.app/'bootstrap/cache/config.php'))}
        with patch.object(mail,'APP',self.app):mail.restore_files(mail_backup,mail_state)
        self.assertNotEqual(d.signature(self.app/'.env')['inode'],env_before['inode'])
        self.assertNotEqual(d.signature(self.app/'bootstrap/cache/config.php')['inode'],cache_before['inode'])
        files=d.FileRecovery(self.app,self.backup,list(state['source']));files.entries=state['source']
        parent=d.NewAuthParent(self.app);parent.entry=state['new_parent']
        compiled=d.CompiledViewRecovery(self.app,Path(state['slot']));compiled.entries=state['compiled_views']
        marker=d.maintenance_enter(self.app,self.backup)
        def runtime():
            d.need(d.preserved_restoration_matches(self.app,state['preserved']),'Restored preserved state differs.')
            d.maintenance_leave(self.app,marker)
        result=d.recover_with_maintenance(files,d.NarrowDirectoryRecovery(compiled,parent),lambda:None,lambda:None,runtime)
        self.assertTrue(result['complete']);self.assertFalse(result['maintenance_retained'])
        self.assertFalse((self.app/'storage/framework/down').exists())
        self.assert_original(allow_mail_restoration=True)

    def test_success_installs_role_sources_only_with_real_ownership_and_private_cache(self):
        self.invoke()
        for name,value in self.payload.items():
            self.assertEqual((self.app/name).read_bytes(),value)
            info=(self.app/name).stat();self.assertEqual((info.st_uid,info.st_gid,stat.S_IMODE(info.st_mode)),(5006,5006,0o644))
        self.assertTrue((self.app/'storage/framework/views/new.php').exists())
        self.assertFalse((self.app/'storage/framework/down').exists())
        self.assertEqual(d.immutable_snapshot(self.app),self.preserved)
    def test_partial_source_restores_original_without_removing_existing_parents(self):
        original=d.FileRecovery.install
        def install(instance,name,*args):
            value=original(instance,name,*args)
            if name=='app/Services/RoleGrantGuard.php':raise RuntimeError('synthetic source interruption')
            return value
        with patch.object(d.FileRecovery,'install',new=install),self.assertRaises(d.Refused):self.invoke()
        self.assert_original();self.assertFalse((self.app/'storage/framework/down').exists())
    def test_persistent_runtime_failure_undoes_code_cache_views_before_probe(self):
        self.fault='runtime'
        with self.assertRaises(d.Refused):self.invoke()
        self.assert_original();self.assertTrue((self.app/'storage/framework/down').exists())
        self.assertEqual(self.events[-1][2],self.original[self.first])
    def test_foreign_source_cache_env_and_auth_children_are_retained(self):
        self.fault='foreign'
        with self.assertRaises(d.Refused):self.invoke()
        self.assertEqual((self.app/self.first).read_bytes(),b'foreign-controller')
        self.assertEqual((self.app/'bootstrap/cache/config.php').read_bytes(),b'foreign-cache')
        self.assertEqual((self.app/'.env').read_bytes(),b'foreign-synthetic-env')
        self.assertEqual((self.app/d.NEW_PARENT/'foreign.txt').read_bytes(),b'foreign-auth-child')
        self.assertTrue((self.app/'storage/framework/down').exists())
        self.assertEqual(d.tree_signature(self.app/'storage/framework/views'),self.original_views)
    def test_maintenance_capture_failure_removes_owned_marker_before_any_source_write(self):
        original=d.atomic
        def atomic(path,*args):
            if path==self.backup/'maintenance.json':raise OSError('synthetic capture failure')
            return original(path,*args)
        with patch.object(d,'atomic',side_effect=atomic),self.assertRaisesRegex(d.Refused,'Pre-activation failed'):
            self.invoke()
        self.assert_original();self.assertFalse((self.app/'storage/framework/down').exists())
    def test_post_marker_journal_failure_adopts_planned_identity_and_prevents_outage(self):
        original=d.atomic
        def atomic(path,*args):
            if not self.fired and path==self.backup/'recovery-state.json' and (self.app/'storage/framework/down').exists():
                self.fired=True;raise OSError('synthetic post-marker journal failure')
            return original(path,*args)
        with patch.object(d,'atomic',side_effect=atomic),self.assertRaisesRegex(d.Refused,'Pre-activation failed'):
            self.invoke()
        self.assertTrue(self.fired);self.assert_original();self.assertFalse((self.app/'storage/framework/down').exists())
    def test_shared_code_and_zoho_locks_reject_overlap_then_release(self):
        locks=(self.boundary/'shared-deploy.lock',self.boundary/'zoho.lock')
        with patch.object(d,'LOCK_PATHS',locks):
            with d.mutation_lock():
                with self.assertRaisesRegex(d.Refused,'Another UAT'):
                    with d.mutation_lock():self.fail('Concurrent mutation was allowed.')
            with d.mutation_lock():pass
        for path in locks:
            info=path.stat();self.assertEqual((info.st_uid,info.st_gid,stat.S_IMODE(info.st_mode)),(0,0,0o600))
    def test_hash_correct_unrecoverable_metadata_refused_before_maintenance(self):
        os.chmod(self.app/self.first,0o664)
        with self.assertRaisesRegex(d.Refused,'metadata'):self.invoke()
        self.assertFalse((self.app/'storage/framework/down').exists())
        self.assertFalse((self.backup/'recovery-state.json').exists())


if __name__=='__main__':unittest.main()

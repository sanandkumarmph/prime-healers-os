"""Real Linux controlling-TTY prompt regression; synthetic input, no SMTP/config.

The child calls only the actual prompt_values/settings path. Stdout and stdin
are separated from the controlling PTY, matching an operator heredoc bootstrap.
The synthetic password is sent only after the password prompt and ECHO is off.
"""

import importlib.util
import json
import os
from pathlib import Path
import signal
import sys
import time
import unittest

LINUX = sys.platform.startswith('linux')
if LINUX:
    import errno
    import pty
    import select
    import termios

HERE = Path(__file__).resolve().parent
SYNTHETIC_PASSWORD = 'PHOS_PTY_SYNTHETIC_ONLY_75f0!$'
HOST = 'smtppro.zoho.in'
MAX_OUTPUT = 32768


@unittest.skipUnless(LINUX, 'Requires real Linux /dev/tty and termios; a skip is not Linux acceptance.')
class ZohoControllingTerminalTests(unittest.TestCase):
    def _child(self, stdout_reader, stdout_writer, *, negative, blocked_echo, port, sender):
        os.close(stdout_reader)
        os.dup2(stdout_writer, 1)
        if stdout_writer != 1:
            os.close(stdout_writer)
        null = os.open(os.devnull, os.O_RDONLY)
        os.dup2(null, 0)
        if null != 0:
            os.close(null)
        forbidden_calls = []

        def forbidden(*args, **kwargs):
            forbidden_calls.append(True)
            raise AssertionError('UnexpectedConfigurationOrTransportCall')

        def report(value, status):
            os.write(1, (json.dumps(value, sort_keys=True) + '\n').encode())
            os._exit(status)

        try:
            if negative:
                try:
                    # Exact old primitive: BufferedRandom requires seekability,
                    # unlike getpass's raw FileIO implementation on this PTY.
                    with open('/dev/tty', 'r+', encoding='utf-8', buffering=1):
                        pass
                except BaseException as error:
                    report({'negative_error_class': type(error).__name__}, 0)
                report({'negative_error_class': None}, 2)

            spec = importlib.util.spec_from_file_location('real_zoho_terminal_fixture', HERE / 'setup-zoho-mail.py')
            helper = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(helper)
            for name in ('execute', 'authenticate_once', 'inspect_configuration', 'configure',
                         'load_target', 'artisan', 'recover', 'snapshot', 'patch_environment', 'atomic'):
                if hasattr(helper, name):
                    setattr(helper, name, forbidden)
            helper.socket.socket = forbidden
            helper.socket.create_connection = forbidden
            helper.subprocess.Popen = forbidden
            helper.subprocess.run = forbidden
            if blocked_echo:
                def no_echo_unavailable(*args, **kwargs):
                    raise termios.error(errno.EPERM, 'SyntheticTerminalEchoControlFailure')
                termios.tcsetattr = no_echo_unavailable

            values = helper.prompt_values()
            expected_sender = sender or 'Prime Healers'
            expected = helper.settings(HOST, port, SYNTHETIC_PASSWORD, expected_sender)
            report({'prompt_completed': values == expected, 'forbidden_calls': len(forbidden_calls),
                    'stdin_is_tty': os.isatty(0), 'stdout_is_tty': os.isatty(1),
                    'port': port, 'sender_default': sender == ''}, 0 if values == expected else 2)
        except BaseException as error:
            # Never include an exception message or settings/password values.
            report({'error_class': type(error).__name__, 'forbidden_calls': len(forbidden_calls)}, 2)

    def _exercise(self, *, negative=False, blocked_echo=False, port=465, sender='PTY Mail Fixture'):
        stdout_reader, stdout_writer = os.pipe()
        pid, master = pty.fork()
        if pid == 0:
            self._child(stdout_reader, stdout_writer, negative=negative, blocked_echo=blocked_echo,
                        port=port, sender=sender)
            os._exit(99)
        os.close(stdout_writer)
        tty_output = bytearray()
        stdout_output = bytearray()
        descriptors = {master, stdout_reader}
        status = None
        cursor = sent = 0
        password_sent_with_echo_off = False
        prompts = [] if negative else [
            (b'Server Configuration Details: ', HOST),
            (b'Port (465 for SMTPS or 587 for STARTTLS): ', str(port)),
            (b'Sender display name [Prime Healers]: ', sender),
            (b'Zoho app-specific password (hidden; never paste into chat): ', SYNTHETIC_PASSWORD),
        ]
        deadline = time.monotonic() + 15
        try:
            while status is None or descriptors:
                self.assertLess(time.monotonic(), deadline, 'Bounded terminal fixture timed out; no secrets were printed.')
                ready, _, _ = select.select(list(descriptors), [], [], 0.05) if descriptors else ([], [], [])
                for descriptor in ready:
                    try:
                        chunk = os.read(descriptor, 4096)
                    except OSError as error:
                        if descriptor == master and error.errno == errno.EIO:
                            chunk = b''  # Expected PTY EOF after the slave closes.
                        else:
                            raise
                    if not chunk:
                        descriptors.discard(descriptor)
                        continue
                    target = tty_output if descriptor == master else stdout_output
                    target.extend(chunk)
                    self.assertLessEqual(len(target), MAX_OUTPUT, 'Terminal fixture output limit exceeded.')
                while sent < len(prompts):
                    marker, response = prompts[sent]
                    position = tty_output.find(marker, cursor)
                    if position < 0:
                        break
                    cursor = position + len(marker)
                    if sent == 3:
                        # Do not type even the synthetic secret if getpass is
                        # using an unsafe echoing fallback on the real terminal.
                        self.assertFalse(termios.tcgetattr(master)[3] & termios.ECHO,
                                         'Password prompt appeared while terminal echo was enabled.')
                        password_sent_with_echo_off = True
                    os.write(master, (response + '\n').encode())
                    sent += 1
                if status is None:
                    ended, value = os.waitpid(pid, os.WNOHANG)
                    if ended:
                        status = value
            self.assertTrue(os.WIFEXITED(status), 'Terminal child did not exit normally.')
            self.assertNotIn(SYNTHETIC_PASSWORD.encode(), tty_output, 'Synthetic password was echoed on the PTY.')
            self.assertNotIn(SYNTHETIC_PASSWORD.encode(), stdout_output, 'Synthetic password leaked to stdout.')
            result = json.loads(stdout_output.decode())
            return result, os.WEXITSTATUS(status), sent, password_sent_with_echo_off
        finally:
            if status is None:
                try:
                    os.killpg(pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                try:
                    os.waitpid(pid, 0)
                except ChildProcessError:
                    pass
            for descriptor in (master, stdout_reader):
                try:
                    os.close(descriptor)
                except OSError:
                    pass

    def test_real_465_prompt_uses_controlling_tty_and_hides_password(self):
        result, status, sent, echo_off = self._exercise(port=465)
        self.assertEqual(status, 0, result)
        self.assertTrue(result['prompt_completed'])
        self.assertEqual((sent, echo_off, result['forbidden_calls']), (4, True, 0))
        self.assertFalse(result['stdin_is_tty'])
        self.assertFalse(result['stdout_is_tty'])
        self.assertEqual(result['port'], 465)

    def test_real_587_prompt_accepts_default_sender_without_password_echo(self):
        result, status, sent, echo_off = self._exercise(port=587, sender='')
        self.assertEqual(status, 0, result)
        self.assertEqual((sent, echo_off, result['forbidden_calls']), (4, True, 0))
        self.assertTrue(result['prompt_completed'])
        self.assertTrue(result['sender_default'])
        self.assertEqual(result['port'], 587)

    def test_old_read_write_open_reproduces_unsupported_operation(self):
        result, status, sent, echo_off = self._exercise(negative=True)
        self.assertEqual((status, sent, echo_off), (0, 0, False))
        self.assertEqual(result, {'negative_error_class': 'UnsupportedOperation'})

    def test_unavailable_echo_control_aborts_before_any_password_is_sent(self):
        result, status, sent, echo_off = self._exercise(blocked_echo=True)
        self.assertEqual(status, 2)
        self.assertEqual(result, {'error_class': 'GetPassWarning', 'forbidden_calls': 0})
        self.assertEqual((sent, echo_off), (3, False))


if __name__ == '__main__':
    unittest.main()

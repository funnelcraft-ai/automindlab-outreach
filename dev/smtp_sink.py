"""Local SMTP catcher (dev only). Accepts any login on 127.0.0.1:2525 and saves each email to ./dev/outbox/*.eml.
Recipients containing "bounce-me" are rejected with a 550, to test bounce handling.
Run:  pip install aiosmtpd && python3 dev/smtp_sink.py"""
import time, os
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "outbox")
os.makedirs(OUT, exist_ok=True)
from aiosmtpd.controller import Controller
from aiosmtpd.smtp import AuthResult
class H:
    async def handle_DATA(self, server, session, envelope):
        fn = os.path.join(OUT, f"{time.time():.6f}.eml")
        open(fn, "wb").write(envelope.original_content or envelope.content)
        if any('bounce-me' in r for r in envelope.rcpt_tos):
            return '550 5.1.1 The email account that you tried to reach does not exist'
        return '250 OK'
def auth(server, session, envelope, mechanism, auth_data):
    return AuthResult(success=True)
c = Controller(H(), hostname='127.0.0.1', port=2525, authenticator=auth, auth_require_tls=False)
c.start()
print(f"SMTP catcher on 127.0.0.1:2525, saving to {OUT}")
while True: time.sleep(3600)

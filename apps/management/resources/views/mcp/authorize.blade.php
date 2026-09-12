<!doctype html>
<html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer"><title>อนุญาตให้ AI เชื่อมต่อ · Management</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f6f8f8;color:#18312d;font-family:system-ui,sans-serif;line-height:1.7}main{max-width:560px;margin:8vh auto;padding:36px;background:white;border:1px solid #dae5e1;border-radius:24px;box-shadow:0 18px 60px #16342e08}.badge{font-size:13px;color:#087e68;letter-spacing:.12em}h1{font-size:28px;line-height:1.4}p,li{color:#526862}li{margin:12px 0}.notice{padding:16px;background:#f0f7f4;border-radius:12px;font-size:14px}button{padding:14px 20px;border:0;border-radius:10px;font:inherit;font-weight:600;cursor:pointer}.primary{background:#086e5a;color:white;width:100%;margin-top:24px}.secondary{background:transparent;color:#526862;width:100%;margin-top:8px}.origin{overflow-wrap:anywhere;font-size:13px}a{color:#086e5a}@media(max-width:600px){main{margin:20px 12px;padding:24px}}
</style></head><body><main>
<span class="badge">MANAGEMENT / CONNECT</span>
<h1>ให้ {{ $client->name }} เชื่อมต่อไหม?</h1>
<p>คุณกำลังอนุญาตให้แอปนี้เข้าถึงข้อมูลผ่านบัญชีที่ล็อกอินอยู่ ตรวจสอบว่าเป็นแอปที่คุณเพิ่งกดเชื่อมต่อ</p>
<p class="origin">ปลายทางของแอป: {{ parse_url($request->input('redirect_uri', $client->redirect_uris[0] ?? ''), PHP_URL_HOST) }}</p>
<ul>@foreach($scopes as $scope)<li>{{ $scope->description }}</li>@endforeach</ul>
<div class="notice">AI จะไม่เห็นรหัสผ่านของคุณ และไม่สามารถอนุมัติหรือเผยแพร่ข้อมูลเองได้ การเชื่อมต่อมีอายุสูงสุด 7 วัน คุณยกเลิกได้ทุกเมื่อใน “เชื่อมต่อ AI”</div>
<form method="post" action="{{ route('passport.authorizations.approve') }}">@csrf
<input type="hidden" name="state" value="{{ $request->state }}"><input type="hidden" name="client_id" value="{{ $client->id }}"><input type="hidden" name="auth_token" value="{{ $authToken }}">
<button class="primary" type="submit">อนุญาตและกลับไปที่แอป →</button></form>
<form method="post" action="{{ route('passport.authorizations.deny') }}">@csrf @method('DELETE')
<input type="hidden" name="state" value="{{ $request->state }}"><input type="hidden" name="client_id" value="{{ $client->id }}"><input type="hidden" name="auth_token" value="{{ $authToken }}">
<button class="secondary" type="submit">ไม่อนุญาต</button></form>
</main></body></html>

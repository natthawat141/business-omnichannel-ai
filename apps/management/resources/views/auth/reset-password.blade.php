<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer"><title>ตั้งรหัสผ่าน</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f4f4f5;color:#18181b;font:16px/1.6 system-ui,sans-serif;padding:48px 20px}main{max-width:460px;margin:auto;background:white;padding:32px;border:1px solid #d4d4d8;border-radius:12px}h1{font-size:26px;margin:0 0 8px}p{color:#52525b}label{display:block;margin-top:20px}input{display:block;width:100%;padding:12px;border:1px solid #71717a;border-radius:6px;font:inherit}button{margin-top:24px;width:100%;padding:12px;background:#18181b;color:white;border:0;border-radius:6px;font:inherit;cursor:pointer}:focus-visible{outline:3px solid #2563eb;outline-offset:3px}.error{color:#991b1b}a{color:#18181b}
    </style>
</head>
<body><main>
    <h1>ตั้งรหัสผ่านของคุณ</h1><p>ใช้รหัสผ่านอย่างน้อย 12 ตัวอักษร ลิงก์นี้ใช้ได้เพียงครั้งเดียว</p>
    <div role="alert" class="error" id="error">{{ $errors->first() }}</div>
    <noscript>กรุณาเปิด JavaScript เพื่อใช้ลิงก์ตั้งรหัสผ่าน</noscript>
    <form id="reset-form" method="POST" action="{{ route('password.update') }}">
        @csrf
        <input id="token" type="hidden" name="token" value="">
        <label for="email">อีเมล</label><input id="email" name="email" type="email" value="" required readonly>
        <label for="password">รหัสผ่านใหม่</label><input id="password" name="password" type="password" minlength="12" maxlength="255" autocomplete="new-password" required>
        <label for="confirm">ยืนยันรหัสผ่านใหม่</label><input id="confirm" name="password_confirmation" type="password" minlength="12" maxlength="255" autocomplete="new-password" required>
        <button id="save" type="submit" disabled>บันทึกรหัสผ่าน</button>
    </form>
    <p><a href="{{ route('login') }}">กลับไปเข้าสู่ระบบ</a></p>
</main>
<script>
    const params = new URLSearchParams(location.hash.slice(1));
    document.getElementById('token').value = params.get('token') || '';
    document.getElementById('email').value = params.get('email') || '';
    history.replaceState(null, '', location.pathname);
    const save = document.getElementById('save');
    save.disabled = !document.getElementById('token').value;
    if (save.disabled) document.getElementById('error').textContent = 'กรุณาเปิดลิงก์ตั้งรหัสผ่านที่ได้รับจากผู้ดูแลระบบ';
    document.getElementById('reset-form').addEventListener('submit', async (event) => {
        event.preventDefault(); save.disabled = true;
        document.getElementById('error').textContent = '';
        try {
            const response = await fetch(event.target.action, {method: 'POST', body: new FormData(event.target), headers: {Accept: 'application/json'}});
            const result = await response.json();
            if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat()[0] : 'บันทึกไม่สำเร็จ กรุณาลองใหม่');
            location.replace('/login');
        } catch (error) { document.getElementById('error').textContent = error.message || 'เชื่อมต่อไม่สำเร็จ กรุณาลองใหม่'; save.disabled = false; }
    });
</script>
</body></html>

import { Head, useForm } from '@inertiajs/react';
import { LogIn } from 'lucide-react';
import type { FormEvent } from 'react';
import { Button, Field, TextInput, Toggle } from '@/components/ui';
import { routes } from '@/lib/routes';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(routes.login);
    }

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-10">
            <Head title="เข้าสู่ระบบ" />
            <div className="w-full max-w-sm">
                <div className="mb-6 text-center">
                    <span className="brand-logo-frame">
                        <img
                            src="/img/aion3-logo.png"
                            alt="Aion3"
                            className="brand-logo h-16 w-auto max-w-full object-contain"
                        />
                    </span>
                    <h1 className="mt-2 text-xl font-semibold tracking-tight text-zinc-900">Knowledge Management</h1>
                    <p className="mt-1 text-sm text-slate-500">จัดการข้อมูลที่ AI ใช้ตอบลูกค้า</p>
                </div>
                <form onSubmit={submit} className="space-y-4 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div>
                        <h2 className="font-semibold text-slate-900">เข้าสู่ระบบผู้ดูแล</h2>
                        <p className="mt-1 text-xs text-slate-500">กรอกบัญชีที่ได้รับเพื่อจัดการข้อมูลในระบบ</p>
                    </div>
                    <Field label="อีเมล" error={errors.email} required>
                        <TextInput
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            error={errors.email}
                            autoFocus
                            autoComplete="username"
                        />
                    </Field>
                    <Field label="รหัสผ่าน" error={errors.password} required>
                        <TextInput
                            type="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            error={errors.password}
                            autoComplete="current-password"
                        />
                    </Field>
                    <Toggle
                        checked={data.remember}
                        onChange={(v) => setData('remember', v)}
                        label="จดจำการเข้าสู่ระบบ"
                    />
                    <Button type="submit" disabled={processing} className="w-full">
                        <LogIn className="h-4 w-4" />
                        เข้าสู่ระบบ
                    </Button>
                </form>
            </div>
        </div>
    );
}

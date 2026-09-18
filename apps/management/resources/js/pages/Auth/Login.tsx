import { Head, router, useForm } from '@inertiajs/react';
import { LogIn } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Button, Field, TextInput, Toggle } from '@/components/ui';
import { auth, googleProvider } from '@/lib/firebase';
import { signInWithPopup } from 'firebase/auth';
import { routes } from '@/lib/routes';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });
    const [firebaseLoading, setFirebaseLoading] = useState(false);
    const [firebaseError, setFirebaseError] = useState<string | null>(null);

    function submit(e: FormEvent) {
        e.preventDefault();
        post(routes.login);
    }

    async function handleGoogleSignIn() {
        setFirebaseLoading(true);
        setFirebaseError(null);
        try {
            const result = await signInWithPopup(auth, googleProvider);
            const idToken = await result.user.getIdToken();

            router.post(
                routes.loginFirebase,
                {
                    id_token: idToken,
                    remember: data.remember,
                },
                {
                    onError: (err) => {
                        setFirebaseError(err.email || err.id_token || 'ไม่สามารถเข้าสู่ระบบได้ กรุณาลองใหม่อีกครั้ง');
                        setFirebaseLoading(false);
                    },
                    onFinish: () => {
                        setFirebaseLoading(false);
                    },
                },
            );
        } catch (err: unknown) {
            setFirebaseLoading(false);
            const firebaseErr = err as { code?: string; message?: string };
            if (firebaseErr.code === 'auth/popup-closed-by-user') {
                return;
            }
            if (firebaseErr.code === 'auth/unauthorized-domain') {
                setFirebaseError('โดเมนนี้ยังไม่ได้รับอนุญาตใน Firebase Console (Authorized Domains)');
                return;
            }
            setFirebaseError(firebaseErr.message || 'เกิดข้อผิดพลาดในการเชื่อมต่อกับ Google');
        }
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
                <div className="space-y-4 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div>
                        <h2 className="font-semibold text-slate-900">เข้าสู่ระบบผู้ดูแล</h2>
                        <p className="mt-1 text-xs text-slate-500">กรอกบัญชีที่ได้รับเพื่อจัดการข้อมูลในระบบ</p>
                    </div>

                    {firebaseError && (
                        <div className="rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-700">
                            {firebaseError}
                        </div>
                    )}

                    <form onSubmit={submit} className="space-y-4">
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
                        <Button type="submit" disabled={processing || firebaseLoading} className="w-full">
                            <LogIn className="h-4 w-4" />
                            เข้าสู่ระบบ
                        </Button>
                    </form>

                    <div className="relative my-4 text-center">
                        <div className="absolute inset-0 flex items-center">
                            <div className="w-full border-t border-slate-200" />
                        </div>
                        <div className="relative flex justify-center text-xs">
                            <span className="bg-white px-2 text-slate-500">หรือ</span>
                        </div>
                    </div>

                    <Button
                        type="button"
                        variant="secondary"
                        disabled={processing || firebaseLoading}
                        onClick={handleGoogleSignIn}
                        className="w-full"
                    >
                        <svg className="h-4 w-4" viewBox="0 0 24 24">
                            <path
                                fill="#4285F4"
                                d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                            />
                            <path
                                fill="#34A853"
                                d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                            />
                            <path
                                fill="#FBBC05"
                                d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"
                            />
                            <path
                                fill="#EA4335"
                                d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"
                            />
                        </svg>
                        {firebaseLoading ? 'กำลังเข้าสู่ระบบ...' : 'เข้าสู่ระบบด้วย Google'}
                    </Button>
                </div>
            </div>
        </div>
    );
}

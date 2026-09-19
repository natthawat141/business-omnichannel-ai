import { Head, router } from '@inertiajs/react';
import { Clock, LogOut, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui';
import { routes } from '@/lib/routes';

interface PendingApprovalProps {
    user: {
        name?: string;
        email?: string;
        approval_status?: string;
        auth_provider?: string;
    };
}

export default function PendingApproval({ user }: PendingApprovalProps) {
    const [checking, setChecking] = useState(false);
    const [message, setMessage] = useState<string | null>(null);

    async function checkStatus() {
        setChecking(true);
        setMessage(null);
        try {
            const res = await fetch(routes.pendingApprovalCheck, {
                headers: {
                    Accept: 'application/json',
                },
            });
            const data = await res.json();
            if (data.is_approved && data.redirect_url) {
                window.location.href = data.redirect_url;
                return;
            }
            setMessage('สถานะปัจจุบัน: ยังอยู่ระหว่างรอการอนุมัติจากผู้ดูแลระบบ');
        } catch {
            setMessage('ไม่สามารถตรวจสอบสถานะได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง');
        } finally {
            setChecking(false);
        }
    }

    function handleLogout() {
        router.post(routes.logout);
    }

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-10">
            <Head title="รอการอนุมัติบัญชี" />
            <div className="w-full max-w-md">
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

                <div className="space-y-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm text-center">
                    <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-amber-50 text-amber-500 border border-amber-200">
                        <Clock className="h-7 w-7" />
                    </div>

                    <div>
                        <h2 className="text-lg font-semibold text-slate-900">บัญชีอยู่ระหว่างรอการอนุมัติ</h2>
                        <p className="mt-1 text-sm text-slate-500">
                            ยินดีต้อนรับคุณ <span className="font-medium text-slate-700">{user.name || user.email}</span>
                        </p>
                    </div>

                    <div className="rounded-md border border-amber-100 bg-amber-50/50 p-4 text-xs text-amber-800 text-left leading-relaxed space-y-2">
                        <div className="flex items-center gap-2 font-medium text-amber-900">
                            <span>อีเมลบัญชี:</span>
                            <span className="font-mono">{user.email}</span>
                        </div>
                        <p>
                            บัญชีของคุณได้รับการลงทะเบียนเข้าสู่ระบบเรียบร้อยแล้ว ขณะนี้อยู่ระหว่างรอผู้ดูแลระบบ (Admin) ตรวจสอบและกำหนดสิทธิ์การเข้าใช้งาน
                        </p>
                        <p className="text-slate-500 text-[11px]">
                            เมื่อผู้ดูแลระบบอนุมัติเรียบร้อยแล้ว คุณจะสามารถเข้าใช้งานระบบได้ทันที
                        </p>
                    </div>

                    {message && (
                        <div className="rounded-md border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
                            {message}
                        </div>
                    )}

                    <div className="space-y-2 pt-2">
                        <Button
                            type="button"
                            onClick={checkStatus}
                            disabled={checking}
                            className="w-full"
                        >
                            <RefreshCw className={`h-4 w-4 ${checking ? 'animate-spin' : ''}`} />
                            {checking ? 'กำลังตรวจสอบสถานะ...' : 'ตรวจสอบสถานะอีกครั้ง'}
                        </Button>

                        <Button
                            type="button"
                            variant="secondary"
                            onClick={handleLogout}
                            className="w-full"
                        >
                            <LogOut className="h-4 w-4" />
                            ออกจากระบบ
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}

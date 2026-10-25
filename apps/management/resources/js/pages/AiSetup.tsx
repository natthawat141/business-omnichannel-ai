import { Head, router } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import {
    ArrowUpRight,
    Check,
    Copy,
    Eye,
    EyeOff,
    FileText,
    KeyRound,
    Plug,
    Terminal,
    Unplug,
} from "lucide-react";
import AdminLayout from "@/components/AdminLayout";
import TerminalBlock from "@/components/TerminalBlock";
import { Button, SelectInput } from "@/components/ui";
import {
    entityOptions,
    localClients,
    localSetupGuide,
    remoteSetupGuide,
    setupPageMarkdown,
    troubleshooting,
    type ConnectionInfo,
    type LocalClient,
    type ProposalEntity,
    type SetupKeyInfo,
    type SetupScope,
} from "@/lib/aiSetupGuide";
import "../../css/ai-setup.css";

interface Props {
    baseUrl: string;
    remoteEnabled: boolean;
    connections: ConnectionInfo[];
    tokens: SetupKeyInfo[];
    antigravityConfig: string;
}
interface Issued {
    id: number;
    key: string;
    expires_at: string;
    client: string;
    scope: string;
}

async function request(path: string, method: string, data?: object) {
    const cookie = document.cookie
        .split("; ")
        .find((value) => value.startsWith("XSRF-TOKEN="));
    const response = await fetch(path, {
        method,
        credentials: "same-origin",
        cache: "no-store",
        headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            "X-XSRF-TOKEN": cookie ? decodeURIComponent(cookie.slice(11)) : "",
        },
        body: data ? JSON.stringify(data) : undefined,
    });
    if (!response.ok)
        throw new Error(
            response.status === 429
                ? "ทำรายการถี่เกินไป รอ 1 นาทีแล้วลองใหม่"
                : [401, 419].includes(response.status)
                  ? "เซสชันหมดอายุ กรุณาโหลดหน้าใหม่และเข้าสู่ระบบอีกครั้ง"
                  : "ทำรายการไม่สำเร็จ กรุณาโหลดหน้าใหม่ก่อนลองอีกครั้ง",
        );
    return response.json();
}
const date = (value: string) =>
    new Date(value).toLocaleString("th-TH", {
        dateStyle: "medium",
        timeStyle: "short",
    });

export default function AiSetup({
    baseUrl,
    remoteEnabled,
    connections,
    tokens,
    antigravityConfig,
}: Props) {
    const [client, setClient] = useState<LocalClient>("codex");
    const [scope, setScope] = useState<SetupScope>("document_metadata");
    const [proposalEntities, setProposalEntities] = useState<ProposalEntity[]>([
        "catalog",
    ]);
    const [minutes, setMinutes] = useState(60);
    const [issued, setIssued] = useState<Issued | null>(null);
    const [showKey, setShowKey] = useState(false);
    const [showHistory, setShowHistory] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);
    const [confirming, setConfirming] = useState<string | null>(null);
    const [feedback, setFeedback] = useState("");
    const [failed, setFailed] = useState(false);
    const [copied, setCopied] = useState("");
    const [manualCopy, setManualCopy] = useState(false);
    const [now, setNow] = useState(Date.now());
    const exportField = useRef<HTMLTextAreaElement>(null);
    const copyFeedbackTimer = useRef<ReturnType<typeof setTimeout> | undefined>(
        undefined,
    );
    const options = {
        baseUrl,
        client,
        scope,
        proposalEntities,
        antigravityConfig,
    };
    const local = localSetupGuide(options);
    const remote = remoteSetupGuide(baseUrl, scope, proposalEntities);
    // Explicit safe export. The issued credential state is never supplied to the generator.
    const pageMarkdown = setupPageMarkdown({
        ...options,
        remoteEnabled,
        minutes,
        connections,
        tokens,
    });
    const active = (row: { revoked_at: string | null; expires_at: string }) =>
        !row.revoked_at && Date.parse(row.expires_at) > now;
    const keyRows = showHistory ? tokens : tokens.filter(active);

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => {
            window.clearInterval(timer);
            clearTimeout(copyFeedbackTimer.current);
        };
    }, []);
    useEffect(() => {
        if (issued && Date.parse(issued.expires_at) <= now) {
            setIssued(null);
            setShowKey(false);
            setFeedback("Local key หมดอายุแล้ว สร้างใหม่เมื่อพร้อมใช้งานต่อ");
        }
    }, [issued, now]);
    useEffect(() => {
        if (manualCopy) {
            exportField.current?.focus();
            exportField.current?.select();
        }
    }, [manualCopy]);

    async function copy(value: string, label = "ข้อความ") {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(label);
            setFailed(false);
            setFeedback(`คัดลอก${label}แล้ว`);
            clearTimeout(copyFeedbackTimer.current);
            copyFeedbackTimer.current = setTimeout(() => setCopied(""), 2000);
            return true;
        } catch {
            setFailed(true);
            setFeedback(
                "คัดลอกอัตโนมัติไม่ได้ เลือกข้อความแล้วกดคัดลอกด้วยตัวเองได้",
            );
            return false;
        }
    }
    async function createKey() {
        if (busy) return;
        setBusy("create");
        setFeedback("");
        setFailed(false);
        setIssued(null);
        setShowKey(false);
        try {
            const result = await request("/admin/ai-setup/keys", "POST", {
                client,
                scope,
                minutes,
                ...(scope === "catalog_proposals"
                    ? { proposal_entities: proposalEntities }
                    : {}),
            });
            setIssued({
                ...result,
                client: local.selected.label,
                scope:
                    scope === "catalog_proposals"
                        ? proposalEntities.join(", ")
                        : "documents:read",
            });
            router.reload({ only: ["tokens"] });
            setFeedback(
                "สร้าง local key แล้ว คัดลอกไปใส่ในเครื่องของคุณก่อนออกจากหน้านี้",
            );
        } catch (error) {
            setFailed(true);
            setFeedback(
                error instanceof Error ? error.message : "สร้าง key ไม่สำเร็จ",
            );
        } finally {
            setBusy(null);
        }
    }
    async function revoke(kind: "key" | "connection", id: number) {
        if (busy) return;
        setBusy(`${kind}:${id}`);
        setFeedback("");
        setFailed(false);
        try {
            await request(
                `/admin/ai-setup/${kind === "key" ? "keys" : "connections"}/${id}`,
                "DELETE",
            );
            if (kind === "key" && issued?.id === id) {
                setIssued(null);
                setShowKey(false);
            }
            router.reload({
                only: [kind === "key" ? "tokens" : "connections"],
            });
            setConfirming(null);
            setFeedback(
                kind === "key"
                    ? "ยกเลิก local key แล้ว"
                    : "ยกเลิกการเชื่อมต่อแล้ว",
            );
        } catch (error) {
            setFailed(true);
            setFeedback(
                error instanceof Error ? error.message : "ยกเลิกไม่สำเร็จ",
            );
        } finally {
            setBusy(null);
        }
    }
    function revokeControl(
        kind: "key" | "connection",
        id: number,
        name: string,
    ) {
        const identity = `${kind}:${id}`;
        return confirming === identity ? (
            <div
                className="setup-confirm"
                role="group"
                aria-label={`ยืนยันยกเลิก ${name}`}
            >
                <p>
                    ยกเลิก {name} ใช่ไหม? แอปจะใช้สิทธิ์นี้ต่อไม่ได้
                    และข้อเสนอที่รอตรวจอาจถูกพักไว้
                </p>
                <div className="setup-actions">
                    <Button
                        variant="secondary"
                        disabled={Boolean(busy)}
                        onClick={() => setConfirming(null)}
                    >
                        ใช้ต่อ
                    </Button>
                    <Button
                        variant="danger"
                        disabled={Boolean(busy)}
                        onClick={() => revoke(kind, id)}
                    >
                        {busy === identity ? "กำลังยกเลิก…" : "ยืนยันยกเลิก"}
                    </Button>
                </div>
            </div>
        ) : (
            <Button
                variant="secondary"
                disabled={Boolean(busy)}
                onClick={() => setConfirming(identity)}
            >
                <Unplug size={15} aria-hidden="true" /> ยกเลิก
                {kind === "key" ? " key" : "การเชื่อมต่อ"}
            </Button>
        );
    }
    const code = (label: string, value: string) => (
        <TerminalBlock label={label} value={value} onCopy={copy} />
    );

    return (
        <AdminLayout title="เชื่อมต่อ AI">
            <Head title="เชื่อมต่อ AI" />
            <div className="ai-setup ai-unified">
                <header className="setup-header">
                    <div>
                        <h1>เชื่อมต่อ AI กับข้อมูลธุรกิจ</h1>
                        <p>
                            เลือกงานที่ต้องการ
                            แล้วเชื่อมผ่านเว็บหรือตั้งค่าบนเครื่องจากหน้านี้ได้เลย
                        </p>
                    </div>
                    <Button
                        variant="secondary"
                        onClick={async () => {
                            setManualCopy(
                                !(await copy(pageMarkdown, "คู่มือทั้งหน้า")),
                            );
                        }}
                    >
                        {copied === "คู่มือทั้งหน้า" ? (
                            <Check size={16} aria-hidden="true" />
                        ) : (
                            <Copy size={16} aria-hidden="true" />
                        )}
                        คัดลอกคู่มือทั้งหน้า
                    </Button>
                </header>
                <nav
                    className="setup-jumps"
                    aria-label="ส่วนในหน้าเชื่อมต่อ AI"
                >
                    <a href="#remote-setup">เชื่อมผ่านเว็บ</a>
                    <a href="#local-setup">ตั้งค่าบนเครื่อง</a>
                    <a href="#connections">แอปที่อนุญาต</a>
                    <a href="#local-keys">จัดการ key</a>
                    <a href="#setup-help">แก้ปัญหา</a>
                </nav>
                <div
                    className="setup-feedback"
                    role="status"
                    aria-live="polite"
                    data-error={failed || undefined}
                >
                    {feedback}
                </div>
                {manualCopy && (
                    <section
                        className="setup-panel"
                        aria-labelledby="copy-fallback-title"
                    >
                        <h2 id="copy-fallback-title">
                            เลือกข้อความเพื่อคัดลอกคู่มือ
                        </h2>
                        <p>สำเนานี้ไม่รวมค่า key ที่เพิ่งสร้าง</p>
                        <textarea
                            ref={exportField}
                            readOnly
                            value={pageMarkdown}
                            aria-label="คู่มือทั้งหน้าสำหรับคัดลอก"
                            rows={12}
                        />
                        <Button
                            variant="secondary"
                            onClick={() => setManualCopy(false)}
                        >
                            ปิดสำเนาคู่มือ
                        </Button>
                    </section>
                )}
                <section
                    className="setup-purpose"
                    aria-labelledby="purpose-title"
                >
                    <div>
                        <h2 id="purpose-title">อยากให้ AI ช่วยงานอะไร?</h2>
                        <p>
                            ตัวเลือกนี้ใช้เตรียมคำแนะนำ
                            สิทธิ์จริงต้องเลือกและยืนยันตอนเชื่อมต่อหรือสร้าง
                            key
                        </p>
                    </div>
                    <div className="setup-purpose-fields">
                        <label htmlFor="setup-scope">งานที่ต้องการ</label>
                        <SelectInput
                            id="setup-scope"
                            value={scope}
                            disabled={Boolean(busy)}
                            onChange={(event) =>
                                setScope(event.target.value as SetupScope)
                            }
                        >
                            <option value="document_metadata">
                                อ่านรายชื่อและสถานะเอกสาร
                            </option>
                            <option value="catalog_proposals">
                                อ่านและเสนอแก้ไขข้อมูลให้ผู้ดูแลตรวจ
                            </option>
                        </SelectInput>
                        {scope === "catalog_proposals" && (
                            <fieldset className="setup-entities">
                                <legend>ประเภทข้อมูลที่ให้เสนอได้</legend>
                                {entityOptions.map((entity) => (
                                    <label key={entity.id}>
                                        <input
                                            type="checkbox"
                                            checked={proposalEntities.includes(
                                                entity.id,
                                            )}
                                            disabled={Boolean(busy)}
                                            onChange={() =>
                                                setProposalEntities(
                                                    (current) =>
                                                        current.includes(
                                                            entity.id,
                                                        )
                                                            ? current.length > 1
                                                                ? current.filter(
                                                                      (e) =>
                                                                          e !==
                                                                          entity.id,
                                                                  )
                                                                : current
                                                            : [
                                                                  ...current,
                                                                  entity.id,
                                                              ],
                                                )
                                            }
                                        />
                                        {entity.label}
                                    </label>
                                ))}
                                <p>
                                    เลือกอย่างน้อยหนึ่งประเภท
                                    ผู้ดูแลยังต้องตรวจและกดใช้ข้อเสนอในระบบ
                                </p>
                            </fieldset>
                        )}
                    </div>
                </section>
                <section
                    id="remote-setup"
                    className="setup-panel"
                    aria-labelledby="remote-title"
                >
                    <div className="setup-section-heading">
                        <div>
                            <h2 id="remote-title">
                                <Plug size={21} aria-hidden="true" />{" "}
                                เชื่อมผ่านเว็บ
                            </h2>
                            <p>
                                สำหรับแอปที่รองรับ Remote MCP + OAuth เช่น
                                ChatGPT หรือ Claude
                                โดยขึ้นอยู่กับแอปและบัญชีของคุณ
                            </p>
                        </div>
                        <a href="/docs/remote-mcp">
                            คู่มือ Remote MCP{" "}
                            <ArrowUpRight size={15} aria-hidden="true" />
                        </a>
                    </div>
                    <p className="setup-connection-state" role="status">
                        {remoteEnabled
                            ? "ระบบเปิดให้ตั้งค่าแล้ว · ตรวจการเชื่อมต่อจากแอปหลังอนุญาต"
                            : "ยังไม่เปิด Remote MCP ในสภาพแวดล้อมนี้ ใช้การตั้งค่าบนเครื่องด้านล่างได้"}
                    </p>
                    <ol className="setup-remote-steps">
                        <li>
                            <h3>1. เพิ่ม Server URL ในแอป AI</h3>
                            <p>
                                เปิด Settings → Apps / Connectors / MCP
                                แล้วเพิ่ม Management
                            </p>
                            <label className="sr-only" htmlFor="mcp-server-url">
                                MCP Server URL
                            </label>
                            <div className="setup-endpoint">
                                <input
                                    id="mcp-server-url"
                                    readOnly
                                    value={remote.endpoint}
                                    spellCheck={false}
                                />
                                <Button
                                    variant="secondary"
                                    disabled={!remoteEnabled}
                                    onClick={() =>
                                        copy(remote.endpoint, "Server URL")
                                    }
                                >
                                    <Copy size={15} aria-hidden="true" /> Copy
                                    URL
                                </Button>
                            </div>
                        </li>
                        <li>
                            <h3>2. ล็อกอินและอนุญาตบนเว็บเรา</h3>
                            <p>
                                ตรวจชื่อแอปและสิทธิ์ก่อนอนุญาต
                                รหัสผ่านกรอกในหน้า Management เท่านั้น
                                การอนุญาตมีอายุสูงสุด 7 วัน
                            </p>
                        </li>
                        <li>
                            <h3>3. เรียกเครื่องมือจริงจากแอป</h3>
                            <p>
                                ลองขอให้ AI ดูเอกสาร หากผลลัพธ์ว่างแต่คำขอสำเร็จ
                                แปลว่าเชื่อมได้และยังไม่มีรายการ การเพิ่ม URL
                                อย่างเดียวยังไม่ยืนยันว่าใช้ได้
                            </p>
                        </li>
                    </ol>
                    {code("Prompt ให้ AI ช่วยเชื่อมผ่านเว็บ", remote.prompt)}
                    <p className="setup-hint">
                        สิทธิ์ที่คำแนะนำขอ: <code>{remote.scopes}</code>
                    </p>
                </section>
                <section
                    id="local-setup"
                    className="setup-panel"
                    aria-labelledby="local-title"
                >
                    <div className="setup-section-heading">
                        <div>
                            <h2 id="local-title">
                                <Terminal size={21} aria-hidden="true" />{" "}
                                ตั้งค่าบนเครื่อง
                            </h2>
                            <p>
                                ใช้ Local CLI / stdio MCP เมื่อมี Node.js 22+,
                                npm และแอป AI บนเครื่องเดียวกัน
                            </p>
                        </div>
                        <a href="/docs/agent-setup">
                            คู่มือ Local CLI{" "}
                            <ArrowUpRight size={15} aria-hidden="true" />
                        </a>
                    </div>
                    <div
                        className="setup-client-picker"
                        role="group"
                        aria-label="เลือกแอปบนเครื่อง"
                    >
                        {localClients.map((item) => (
                            <Button
                                key={item.id}
                                variant={
                                    client === item.id ? "primary" : "secondary"
                                }
                                aria-pressed={client === item.id}
                                disabled={Boolean(busy)}
                                onClick={() => setClient(item.id)}
                            >
                                {item.label}
                            </Button>
                        ))}
                    </div>
                    <div className="setup-local-step">
                        <h3>1. ให้ {local.selected.label} ช่วยตั้งค่า</h3>
                        <p>
                            คัดลอก prompt ให้ agent
                            ตรวจเครื่องและเตรียมการติดตั้งก่อนสร้าง key
                        </p>
                        <TerminalBlock
                            label={`Prompt ตั้งค่า ${local.selected.label}`}
                            value={local.skillPrompt}
                            onCopy={copy}
                            className="docs-terminal--prompt"
                        />
                    </div>
                    <div className="setup-local-step">
                        <h3>2. ติดตั้งและลงทะเบียนด้วยตัวเอง</h3>
                        <p>
                            หากตั้งค่าเอง ให้เปิด Bash ในโฟลเดอร์ที่เลือก
                            แล้วทำตามคำสั่งตามลำดับ
                            ขั้นตอนนี้แสดงไว้ครบโดยไม่ต้องเปิด Advanced
                        </p>
                        {code("ติดตั้ง package · Bash", local.installCommand)}
                    </div>
                    <div id="create-local-key" className="setup-local-step">
                        <h3>3. สร้าง key แล้วลงทะเบียนแอป</h3>
                        <p>
                            ใช้เฉพาะการเชื่อมต่อบนเครื่อง ใส่ key ใน Terminal
                            หรือ private config ด้วยตัวเอง ไม่มี key อยู่ใน
                            prompt หรือสำเนาทั้งหน้า
                        </p>
                        <div className="setup-key-create">
                            <div>
                                <label htmlFor="setup-key-minutes">
                                    อายุ key
                                </label>
                                <SelectInput
                                    id="setup-key-minutes"
                                    value={minutes}
                                    disabled={Boolean(busy)}
                                    onChange={(e) =>
                                        setMinutes(Number(e.target.value))
                                    }
                                >
                                    <option value={15}>15 นาที</option>
                                    <option value={60}>60 นาที</option>
                                    <option value={240}>4 ชั่วโมง</option>
                                </SelectInput>
                            </div>
                            <Button
                                disabled={Boolean(busy)}
                                onClick={createKey}
                            >
                                <KeyRound size={16} aria-hidden="true" />
                                {busy === "create"
                                    ? "กำลังสร้าง…"
                                    : `สร้าง key สำหรับ ${local.selected.label}`}
                            </Button>
                        </div>
                        <p className="setup-hint">
                            สิทธิ์ของ key:{" "}
                            {scope === "document_metadata"
                                ? "documents:read"
                                : `agent:read, changes:write, ${proposalEntities.map((e) => `agent:${e}`).join(", ")}`}
                        </p>
                        {issued && (
                            <div
                                className="setup-issued"
                                role="group"
                                aria-label="Local key ที่เพิ่งสร้าง"
                            >
                                <p>
                                    <strong>Key สำหรับ {issued.client}</strong>{" "}
                                    · {issued.scope} · เหลือ{" "}
                                    {Math.max(
                                        0,
                                        Math.ceil(
                                            (Date.parse(issued.expires_at) -
                                                now) /
                                                60000,
                                        ),
                                    )}{" "}
                                    นาที
                                </p>
                                <label htmlFor="issued-local-key">
                                    คัดลอกก่อนออกจากหน้านี้ ระบบจะแสดงครั้งเดียว
                                </label>
                                <div className="setup-secret">
                                    <input
                                        id="issued-local-key"
                                        type={showKey ? "text" : "password"}
                                        value={issued.key}
                                        readOnly
                                        spellCheck={false}
                                        autoComplete="off"
                                    />
                                    <Button
                                        variant="secondary"
                                        aria-label={
                                            showKey
                                                ? "ซ่อน local key"
                                                : "แสดง local key"
                                        }
                                        aria-pressed={showKey}
                                        onClick={() =>
                                            setShowKey((value) => !value)
                                        }
                                    >
                                        {showKey ? (
                                            <EyeOff size={16} />
                                        ) : (
                                            <Eye size={16} />
                                        )}
                                    </Button>
                                    <Button
                                        variant="secondary"
                                        onClick={() =>
                                            copy(issued.key, " local key")
                                        }
                                    >
                                        <Copy size={15} aria-hidden="true" />{" "}
                                        คัดลอก key
                                    </Button>
                                </div>
                            </div>
                        )}
                        <p>
                            {client === "antigravity"
                                ? "เตรียม global config นอก repository แล้วใส่ key แทน PASTE_KEY_LOCALLY ด้วยตัวเอง รักษา server อื่นที่มีอยู่"
                                : "ใช้ Bash เดิม รันคำสั่งนี้แล้วกรอก key ตอนขึ้น Key: ตัวอักษรจะไม่แสดงระหว่างกรอก"}
                        </p>
                        {code(
                            client === "antigravity"
                                ? "เตรียม Antigravity"
                                : "ตั้งค่า environment · Bash",
                            local.environment,
                        )}
                        {code(
                            client === "antigravity"
                                ? "Antigravity MCP config"
                                : "ลงทะเบียน client · Bash",
                            local.command,
                        )}
                        <a href={local.skillPath}>
                            อ่านข้อจำกัดและเครื่องมือที่ใช้ได้{" "}
                            <ArrowUpRight size={15} aria-hidden="true" />
                        </a>
                    </div>
                    <div className="setup-local-step">
                        <h3>4. ตรวจการเชื่อมต่อ</h3>
                        <p>
                            เปิดหรือรีเฟรชแอปตามคู่มือ
                            แล้วตรวจจากผลเรียกใช้เครื่องมือจริง
                            หน้านี้ไม่ได้ตรวจเครื่องของคุณแทน
                        </p>
                        {code("คำสั่งตรวจการเชื่อมต่อ", local.verification)}
                    </div>
                </section>
                <section
                    className="setup-panel"
                    aria-labelledby="workflow-title"
                >
                    <h2 id="workflow-title">
                        ให้ AI เตรียมข้อมูล แล้วตรวจข้อเสนอในระบบ
                    </h2>
                    <p>
                        อ่าน schema → ค้นรายการ → อ่านรายละเอียดและเวอร์ชัน →
                        preview → ส่งข้อเสนอเมื่อคุณสั่ง → ผู้ดูแลตรวจและกดใช้
                    </p>
                    <p className="setup-hint">
                        การส่งข้อเสนอยังไม่แก้ข้อมูลจริง ไม่มีเครื่องมือ
                        approve, apply, publish, SQL หรืออ่านเนื้อหา PDF
                    </p>
                    <a href="/admin/agent-changes">
                        ตรวจข้อเสนอ AI{" "}
                        <ArrowUpRight size={15} aria-hidden="true" />
                    </a>
                </section>
                <section
                    id="connections"
                    className="setup-panel"
                    aria-labelledby="connections-title"
                >
                    <h2 id="connections-title">แอปที่คุณอนุญาต</h2>
                    <p>
                        แสดง 30 รายการล่าสุด “มีการเรียกใช้”
                        หมายถึงผ่านการยืนยันตัวตนแล้ว
                        ไม่ได้ยืนยันว่าทุกเครื่องมือสำเร็จ
                    </p>
                    {!connections.length && (
                        <p className="setup-empty">
                            ยังไม่มีแอปที่อนุญาต
                            เริ่มจากขั้นตอนเชื่อมผ่านเว็บด้านบน
                        </p>
                    )}
                    {connections.map((connection) => (
                        <div
                            className="setup-credential-row"
                            key={connection.id}
                        >
                            <div>
                                <h3>{connection.name}</h3>
                                <p>
                                    {connection.revoked_at
                                        ? "ยกเลิกแล้ว"
                                        : !active(connection)
                                          ? "หมดอายุแล้ว"
                                          : connection.last_used_at
                                            ? "มีการเรียกใช้ MCP แล้ว"
                                            : "อนุญาตแล้ว · รอแอปเรียกใช้"}{" "}
                                    · หมดอายุ {date(connection.expires_at)}
                                </p>
                                <ul>
                                    {connection.scopes.map((s) => (
                                        <li key={s}>{s}</li>
                                    ))}
                                </ul>
                            </div>
                            {active(connection) &&
                                revokeControl(
                                    "connection",
                                    connection.id,
                                    connection.name,
                                )}
                        </div>
                    ))}
                </section>
                <section
                    id="local-keys"
                    className="setup-panel"
                    aria-labelledby="keys-title"
                >
                    <div className="setup-section-heading">
                        <div>
                            <h2 id="keys-title">จัดการ local key</h2>
                            <p>
                                มี {tokens.filter(active).length} key
                                ที่ยังไม่หมดอายุ · แสดงประวัติสูงสุด 50
                                รายการล่าสุด
                            </p>
                        </div>
                        <label className="setup-checkbox">
                            <input
                                type="checkbox"
                                checked={showHistory}
                                onChange={(event) =>
                                    setShowHistory(event.target.checked)
                                }
                            />
                            แสดงประวัติทั้งหมด
                        </label>
                    </div>
                    {!keyRows.length && (
                        <p className="setup-empty">
                            {showHistory
                                ? "ยังไม่มีประวัติ key"
                                : "ไม่มี local key ที่ยังใช้งานอยู่"}{" "}
                            ·{" "}
                            <a href="#create-local-key">
                                สร้างเมื่อพร้อมตั้งค่าบนเครื่อง
                            </a>
                        </p>
                    )}
                    {keyRows.map((key) => (
                        <div className="setup-credential-row" key={key.id}>
                            <div>
                                <h3>{key.name}</h3>
                                <p>
                                    {key.revoked_at
                                        ? "ยกเลิกแล้ว"
                                        : active(key)
                                          ? "ยังไม่หมดอายุ"
                                          : "หมดอายุแล้ว"}{" "}
                                    · หมดอายุ {date(key.expires_at)}
                                </p>
                                <p className="setup-hint">
                                    {key.abilities.join(", ")} ·{" "}
                                    {key.last_used_at
                                        ? `ใช้ล่าสุด ${date(key.last_used_at)}`
                                        : "ยังไม่เคยใช้"}
                                </p>
                            </div>
                            {active(key) &&
                                revokeControl("key", key.id, key.name)}
                        </div>
                    ))}
                </section>
                <section
                    id="setup-help"
                    className="setup-panel"
                    aria-labelledby="help-title"
                >
                    <h2 id="help-title">ถ้าเชื่อมต่อไม่ได้</h2>
                    <dl className="setup-troubleshooting">
                        {troubleshooting.map(([issue, recovery]) => (
                            <div key={issue}>
                                <dt>{issue}</dt>
                                <dd>{recovery}</dd>
                            </div>
                        ))}
                    </dl>
                </section>
                <footer className="setup-footer">
                    <a href="/docs/remote-mcp">
                        <FileText size={16} aria-hidden="true" /> คู่มือ Remote
                        MCP
                    </a>
                    <a href="/docs/agent-setup">คู่มือ Local CLI / MCP</a>
                    <span>คัดลอกคู่มือทั้งหน้าได้โดยไม่รวมค่า key</span>
                </footer>
            </div>
        </AdminLayout>
    );
}

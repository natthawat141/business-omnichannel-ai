<!doctype html>
<html lang="th" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="คู่มือเชื่อมต่อ Management Agent กับ Codex, Claude Code และ Antigravity ผ่าน CLI และ MCP">
    <title>เชื่อมต่อ AI · Aion3 Docs</title>
    <link rel="alternate" type="text/markdown" href="{{ $baseUrl }}/docs/agent-setup.md">
    <link href="https://fonts.bunny.net/css?family=noto-sans-thai:400,500,600,700" rel="stylesheet">
    @include('docs.styles')
    <script>try { document.documentElement.dataset.theme = localStorage.getItem('aion3-theme') === 'dark' ? 'dark' : 'light'; } catch (_) {}</script>
</head>
<body>
<a class="skip" href="#content">ข้ามไปเนื้อหา</a>
<header class="topbar"><a class="brand" href="/docs/agent-setup"><span class="brand-mark">A3</span><strong>Aion3</strong><span class="brand-divider">/</span><span>Docs</span></a><div class="header-actions"><button id="theme-toggle" type="button" aria-label="สลับธีมสว่างและมืด">สลับธีม</button><a class="open-app" href="/admin/ai-setup">เปิด Agent setup <span aria-hidden="true">↗</span></a></div></header>
<div class="docs-layout">
    <aside class="sidebar">
        <details open><summary>เนื้อหาในคู่มือ</summary><nav aria-label="สารบัญเอกสาร">
            <p class="nav-group">เริ่มต้นใช้งาน</p>
            <a href="#overview">ภาพรวมการเชื่อมต่อ</a><a href="#install">ติดตั้งเครื่องมือ</a><a href="#credentials">สร้างและใส่ key</a>
            <p class="nav-group">เชื่อมต่อแอป</p>
            <a href="#codex">Codex CLI</a><a href="#claude-code">Claude Code</a><a href="#antigravity">Antigravity <span class="new-label">ใหม่</span></a><a href="#terminal">Terminal / CLI</a>
            <p class="nav-group">อ้างอิง</p>
            <a href="#tools">เครื่องมืออ่านเอกสาร</a><a href="#proposals">เสนอข้อมูลให้ผู้ดูแล</a><a href="#troubleshooting">แก้ปัญหาการเชื่อมต่อ</a><a href="#cloud">Work และ Cowork</a>
        </nav></details>
        <div class="sidebar-bottom"><span>สำหรับ AI agents</span><a href="/docs/agent-setup.md">อ่าน Markdown ↗</a><a href="/skills/document-intake/SKILL.md">Document skill ↗</a><a href="/skills/management-proposals/SKILL.md">Proposal skill ↗</a><a href="/llms.txt">ดัชนี llms.txt ↗</a></div>
    </aside>
    <main id="content" tabindex="-1">
        <div class="breadcrumb">Documentation <span>/</span> Integrations</div>
        <section id="overview" class="intro">
            <div class="heading-meta"><span class="tag">Local MCP · CLI</span><span>Management Agent v0.2.0</span></div>
            <h1>ให้ AI เชื่อมกับ<br>เอกสารของธุรกิจ</h1>
            <p class="lead">เลือกแอปที่คุณใช้ ให้ agent ช่วยติดตั้ง แล้วใส่ key เพื่อเริ่มอ่านรายการและสถานะเอกสารจากระบบจริง</p>
            <div class="intro-actions"><a class="primary" href="/admin/ai-setup">เริ่มด้วย Agent setup <span aria-hidden="true">→</span></a><a class="text-link" href="/docs/agent-setup.md">เปิดเอกสารสำหรับ AI</a></div>
            <div class="callout"><strong>อ่านรายการได้ และเสนอข้อมูลได้แบบ draft เท่านั้น</strong><p>key ปกติอ่านชื่อไฟล์และสถานะ ส่วน proposal key ส่งได้เพียงชุดข้อมูลที่ผู้ดูแลต้องตรวจ อนุมัติ และกดใช้งานแยกต่างหาก ระบบไม่เปิด PDF, ทำ OCR หรือเผยแพร่ข้อมูลเอง</p></div>
        </section>
        <section id="install">
            <div class="section-kicker">เตรียมเครื่องของคุณ</div><h2>ติดตั้งครั้งแรก</h2>
            <p>ต้องมี Node.js 22 ขึ้นไป, npm และแอป AI บนเครื่องเดียวกัน ไม่ต้องใช้ Docker หรือฐานข้อมูลบนเครื่องคุณ การติดตั้งต้องเข้าถึง npm registry ได้</p>
            <ol class="steps"><li>เปิด Terminal แบบ Bash ในโฟลเดอร์ว่าง</li><li>รันคำสั่งด้านล่างเพื่อติดตั้งแพ็กเกจและเข้าโฟลเดอร์เครื่องมือ</li><li>ถ้ามีโฟลเดอร์เดิมอยู่แล้ว ให้ตรวจของเดิมก่อน อย่าติดตั้งทับโดยไม่ตรวจสอบ</li></ol>
            @include('docs.code', ['id' => 'install-code', 'label' => 'Bash · ติดตั้ง package', 'code' => "npm install --prefix ./management-agent {$baseUrl}/downloads/document-intake-agent-0.2.0.tgz\ncd ./management-agent/node_modules/@local/document-intake-agent"])
            <p class="caption">แพ็กเกจประกอบด้วย CLI และ stdio MCP เท่านั้น ไม่มี key หรือเอกสารลูกค้า ไม่ต้อง clone repository</p>
        </section>
        <section id="credentials">
            <h2>สร้าง key เมื่อพร้อมเชื่อมต่อ</h2><p>ไปที่ <a href="/admin/ai-setup">Agent setup</a> เลือกแอป แล้วเลือกสิทธิ์ก่อนสร้าง key อายุ 15 นาที, 1 ชั่วโมง หรือ 4 ชั่วโมง: <code>documents:read</code> สำหรับ metadata หรือ proposal key เฉพาะ Catalog/FAQ/Knowledge ที่เลือก Proposal key ไม่มีสิทธิ์ approve, apply หรือ publish</p>
            <h3>Codex / Claude Code / Terminal</h3><p>รันใน Bash ด้วยตัวเอง แล้วใส่ key เมื่อขึ้น <code>Key:</code> ตัวอักษรจะไม่แสดง ใช้ Terminal นี้ต่อในขั้นถัดไป อย่าใส่ key ในแชตหรือ Terminal ที่ agent บันทึกข้อมูลอยู่</p>
            @include('docs.code', ['id' => 'env-code', 'label' => 'Bash · ใส่ key แบบซ่อนข้อความ', 'code' => "export MANAGEMENT_API_BASE_URL="."'".str_replace("'", "'\\''", $baseUrl)."'"."\nread -r -s -p 'Key: ' MANAGEMENT_API_TOKEN\nexport MANAGEMENT_API_TOKEN\necho"])
            <p class="caption">Antigravity ใช้ key ใน global config แทนขั้นตอนนี้ ดูวิธีใส่ key ในหัวข้อ Antigravity ด้านล่าง</p>
        </section>
        <section id="codex"><div class="section-kicker">Local client</div><h2>Codex CLI</h2><p>ใช้ Terminal เดิมในโฟลเดอร์ package คำสั่งเปิด Codex ด้านล่างส่งตัวแปร key ให้ MCP โดยไม่ใส่ค่าจริงในคำสั่ง</p>
            @include('docs.code', ['id' => 'codex-code', 'label' => 'Bash · ลงทะเบียนและเปิด Codex', 'code' => 'codex mcp add document-intake -- node "$PWD/bin/document-intake-mcp.js"'."\n".'codex -c \'mcp_servers.document-intake.env_vars=["MANAGEMENT_API_BASE_URL","MANAGEMENT_API_TOKEN"]\''])
            <p class="caption">ถ้ามี document-intake ใน config แล้ว ให้ตรวจ path เดิมก่อน ไม่ลบหรือเขียนทับ server อื่น แอปที่เปิดอยู่ก่อนจะไม่รับ environment ใหม่อัตโนมัติ</p>
        </section>
        <section id="claude-code"><h2>Claude Code</h2><p>ลงทะเบียน stdio server แล้วเปิด Claude Code จาก Terminal ที่ใส่ key ไว้</p>
            @include('docs.code', ['id' => 'claude-code-example', 'label' => 'Bash · ลงทะเบียนและเปิด Claude Code', 'code' => 'claude mcp add --transport stdio document-intake -- node "$PWD/bin/document-intake-mcp.js"'."\n".'claude'])
        </section>
        <section id="antigravity"><div class="heading-meta"><span class="tag">เพิ่มช่องทางใหม่</span></div><h2>Antigravity</h2><p>เพิ่มเป็น custom MCP แบบ local stdio ผ่าน <strong>MCP Servers → Manage MCP Servers → View raw config</strong> ชื่อเมนูอาจต่างกันตามรุ่น เปิด config จากแอปเพื่อให้ได้ไฟล์ที่รุ่นนั้นใช้งานจริง</p>
            <ol class="steps"><li>สำรอง config เดิม เลือก <strong>global config นอก repository</strong> เพื่อไม่ให้ key เข้า Git</li><li>รวมเฉพาะ <code>document-intake</code> ใน <code>mcpServers</code> อย่าแทนที่ server อื่น</li><li>แก้ path ของ <code>node</code> และไฟล์ MCP เป็น absolute path จริง ใช้ <code>command -v node</code> และ <code>pwd</code> ตรวจ path ได้</li><li>ใส่ key แทน <code>PASTE_KEY_LOCALLY</code> ด้วยตัวเอง บันทึกและกด Refresh MCP Servers แล้วทดสอบอ่านข้อมูล</li></ol>
            @include('docs.code', ['id' => 'antigravity-code', 'label' => 'JSON · template ไม่มี key จริง', 'code' => $antigravityConfig])
            <div class="callout"><strong>ไฟล์ที่ใส่ key แล้วเป็นความลับ</strong><p>อย่าส่งให้ agent อ่านหรือแนบในแชต จำกัดสิทธิ์ไฟล์ให้เฉพาะบัญชีคุณบน macOS/Linux หรือใช้สิทธิ์ผู้ใช้บน Windows เมื่อ key หมดอายุให้เปลี่ยนในไฟล์แล้ว Refresh ใหม่</p></div>
            <p class="caption">รูปแบบ config ตรวจตาม <a href="https://antigravity.google/docs/mcp" target="_blank" rel="noreferrer">เอกสาร Google Antigravity</a> ยังไม่ได้ทดสอบบัญชี Antigravity ของคุณกับ key จริง</p>
        </section>
        <section id="terminal"><h2>Terminal / CLI</h2><p>ถ้าต้องการอ่านข้อมูลโดยไม่เปิดแอป AI ใช้ CLI จากโฟลเดอร์ package ใน Terminal ที่ใส่ key แล้ว</p>
            @include('docs.code', ['id' => 'cli-code', 'label' => 'Bash · เอกสารล่าสุด 10 รายการ', 'code' => 'node ./bin/document-intake.js list --limit 10'])
        </section>
        <section id="tools"><h2>ลองเรียกเครื่องมืออ่านเอกสาร</h2><p>หลัง client โหลด MCP แล้ว ลองคัดลอกข้อความนี้ไปถาม AI การติดตั้ง config อย่างเดียวยังไม่ยืนยันว่าเชื่อมสำเร็จ</p>
            @include('docs.code', ['id' => 'test-code', 'label' => 'Prompt · ทดสอบการอ่านจริง', 'code' => 'ใช้ document_list ด้วย limit 10 และ page 1 แสดงชื่อและสถานะเอกสาร ถ้าไม่มีรายการให้บอกตามจริง'])
            <div class="table-scroll"><table><thead><tr><th>เครื่องมือ</th><th>ใช้ทำอะไร</th><th>ขอบเขต</th></tr></thead><tbody><tr><td><code>document_list</code></td><td>ดูรายการและกรองสถานะ</td><td>limit 1–50, page 1–1000</td></tr><tr><td><code>document_get</code></td><td>ดู metadata ของเอกสารหนึ่งรายการ</td><td>ID จำนวนเต็มบวกจากรายการจริง</td></tr></tbody></table></div>
            <p>สถานะที่กรองได้: <code>uploaded</code>, <code>extracting</code>, <code>ready</code>, <code>ocr_required</code>, <code>failed</code> แม้สถานะเป็น ready ก็ยังอ่านเนื้อหา PDF ผ่านเครื่องมือนี้ไม่ได้</p><p class="caption">ผลสำเร็จที่มีรายการว่างหมายถึงเชื่อมได้แต่ไม่มีข้อมูลตรงเงื่อนไข ห้ามสรุปว่าเชื่อมสำเร็จจากการติดตั้งเพียงอย่างเดียว</p>
        </section>
        <section id="proposals"><div class="section-kicker">Human-reviewed write path</div><h2>เสนอข้อมูลให้ผู้ดูแลตรวจ</h2><p>เลือก “ส่งข้อเสนอข้อมูลให้ผู้ดูแลตรวจ” ใน Agent setup และเลือกเฉพาะ entity ที่จำเป็น ระบบสร้าง key ที่มี <code>agent:read</code>, <code>changes:write</code> และสิทธิ์ entity ที่เลือก ไม่มีสิทธิ์ SQL, upload, approve, apply หรือ publish</p>
            <ol class="steps"><li>AI อ่าน <code>agent_schema</code> ก่อนทุกครั้ง</li><li>ค้นหา/อ่าน record ที่จำกัดด้วย <code>agent_records_search</code> และ <code>agent_record_get</code></li><li>ส่ง <code>agent_changes_preview</code> เพื่อตรวจ diff โดยยังไม่เปลี่ยนข้อมูล</li><li>เมื่อเจ้าของสั่งเท่านั้น จึงใช้ <code>agent_changes_submit</code> พร้อม idempotency key</li><li>ผู้ดูแลเปิด <strong>ตรวจข้อเสนอ AI</strong> ตรวจแหล่งอ้างอิง กดอนุมัติ แล้วกดใช้งานแยกต่างหาก</li></ol>
            @include('docs.code', ['id' => 'proposal-prompt', 'label' => 'Prompt · ทดสอบ proposal อย่างปลอดภัย', 'code' => 'ใช้ agent_schema อ่านข้อจำกัด แล้ว preview ข้อเสนอจากเอกสารโดยห้าม apply หรือ publish ระบุข้อมูลที่ไม่พบให้เป็น unknown/เว้นไว้'])
            <div class="callout"><strong>ข้อมูลใหม่เริ่มเป็น draft</strong><p>Catalog ที่สร้างจะ unpublished และ availability เป็น unknown; FAQ/Knowledge จะ inactive ผู้ดูแลต้องตรวจและเผยแพร่ด้วยขั้นตอนปกติภายหลัง หาก record เปลี่ยนระหว่างรอ ระบบหยุดทั้งชุดและรายงาน conflict</p></div>
            <p class="caption">อ่าน <a href="/skills/management-proposals/SKILL.md">Management Proposals SKILL.md</a> ก่อนใช้งานกับ PDF หรือ brochure ทุกครั้ง</p>
        </section>
        <section id="troubleshooting"><h2>เมื่อเชื่อมต่อไม่สำเร็จ</h2><div class="troubleshooting">
            <details><summary>401 · Key ใช้ไม่ได้หรือหมดอายุ</summary><p>สร้างใหม่ใน Agent setup แล้วใส่ใน Terminal หรือ Antigravity global config และเปิด client/Refresh ใหม่ อย่าส่ง key ให้ AI ตรวจในแชต</p></details>
            <details><summary>403 · สิทธิ์ไม่ตรงกับเครื่องมือ</summary><p>ให้ผู้ดูแลตรวจว่าเป็น key สิทธิ์ documents:read หรือ proposal entity ที่เลือกไว้ ไม่ต้องเพิ่มสิทธิ์เป็นทุกอย่าง</p></details>
            <details><summary>429 · เรียกถี่เกินไป</summary><p>รอตามเวลาที่ระบบแจ้ง หลีกเลี่ยงการ retry วนซ้ำ</p></details>
            <details><summary>Proxy timeout หรือไม่พบเครื่องมือ</summary><p>ตรวจเครือข่าย, absolute path และการโหลด MCP แยกกัน Timeout ไม่ยืนยันว่า key ผิด ห้ามปิดการตรวจ HTTPS เพื่อแก้ปัญหา</p></details>
        </div></section>
        <section id="cloud"><h2>เชื่อมผ่านเว็บ ไม่ต้องคัดลอก key</h2><p>วิธี Remote MCP + OAuth ให้คุณล็อกอินบนเว็บ Management แล้วกดอนุญาต แยกจาก Local CLI ในคู่มือหน้านี้ ตรวจสถานะบริการและความพร้อมของแอปก่อนใช้ การอ่าน skill ไม่ได้ให้สิทธิ์เข้าถึงข้อมูล</p><a class="text-link" href="/docs/remote-mcp">อ่านคู่มือเชื่อมต่อแบบง่าย →</a></section>
        <footer class="doc-footer"><span>Aion3 · Management Agent</span><a href="/skills/management-proposals/SKILL.md">ดูสัญญา proposal ↗</a></footer>
    </main>
</div>
<div id="copy-status" class="copy-status" role="status" aria-live="polite"></div>
<script>
if (window.matchMedia('(max-width: 720px)').matches) document.querySelector('.sidebar details').open = false;
document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
    const target = document.getElementById(button.dataset.copy);
    const status = document.getElementById('copy-status');
    try { await navigator.clipboard.writeText(target.textContent); status.textContent = 'คัดลอกแล้ว'; }
    catch (_) { status.textContent = 'คัดลอกอัตโนมัติไม่ได้ เลือกข้อความในกล่องแล้วคัดลอกเอง'; }
    status.classList.add('visible');
    clearTimeout(window.docsCopyTimer);
    window.docsCopyTimer = setTimeout(() => status.classList.remove('visible'), 4000);
}));
document.getElementById('theme-toggle').addEventListener('click', () => {
    const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = theme;
    try { localStorage.setItem('aion3-theme', theme); } catch (_) {}
});
const links = [...document.querySelectorAll('.sidebar nav a')];
function markSection() { const current = location.hash || '#overview'; links.forEach(link => { if (link.getAttribute('href') === current) link.setAttribute('aria-current', 'location'); else link.removeAttribute('aria-current'); }); }
window.addEventListener('hashchange', markSection); markSection();
</script>
</body></html>

# Product

## Register

product

## Users

พนักงานสำนักงาน การเงิน และจัดซื้อของ Theelincon ที่ใช้ระบบทั้งวันในการออกใบแจ้งหนี้ จัดการ PO/PR สัญญาจ้าง รายงานหน้างาน cash ledger และงานเอกสารที่เกี่ยวข้อง

บริบทการใช้งาน: โต๊ะทำงาน office หรือ laptop ในสำนักงาน ต้องเข้าถึงข้อมูลเร็ว อ่านตัวเลขและสถานะได้ชัด สลับระหว่างโมดูลบ่อย

## Product Purpose

ระบบ ERP/operations ภายในสำหรับจัดการงานเอกสารและการเงินของธุรกิจก่อสร้าง/บริการ — รวม invoice, purchase order, hire contract, daily site report, stock, payslip, leave และรายงานภาษี

ความสำเร็จ = ผู้ใช้ทำงานเสร็จเร็วขึ้น ลดคลิกและการสับสน อ่านข้อมูลในตารางและฟอร์มได้ทันที โดยไม่เสียความน่าเชื่อถือของข้อมูล — **และรู้สึกว่าระบบมีเอกลักษณ์ Theelincon (อบอุ่น มั่นใจ) ไม่ใช่ template เทาๆ**

## Brand Personality

**อบอุ่น · กล้า · น่าเชื่อถือ** (warm, bold, trustworthy)

โทน construction/orange — รู้สึกเป็นงานก่อสร้าง/operations จริง ไม่ใช่ SaaS ทั่วไป

โทนเสียง: ตรงไปตรงมา มั่นใจ hierarchy ชัด เน้นความถูกต้องของตัวเลขและสถานะ ภาษาไทยที่อ่านง่าย

**Visual energy (2026 refresh):** ระดับ **bold** — welcome/hero และ accent ส้ม copper เด่นขึ้น contrast แรงขึ้น แต่ยังเป็น product UI ไม่ใช่ landing page

## Anti-references

- Generic SaaS template: ฟอนต์ Inter/default system stack, card ซ้อน card, palette เทาจืดๆ ไม่มีเอกลักษณ์
- UI ที่สวยแต่ช slow workflow — animation หรือ decoration ที่ขัดกับงาน data-heavy
- Marketing landing page aesthetic บนหน้า app ที่ต้องทำงานจริง
- Glassmorphism, gradient ม่วง–น้ำเงิน (AI slop), neon accent บนพื้นมืด
- Scroll-fade-rise ทุก section, motion ซ้ำๆ ที่รบกวนการอ่านตาราง

## Design Principles

1. **Speed to task** — ทุกหน้าต้องช่วยให้จบงานเร็วขึ้น: hierarchy ชัด, action หลักเด่น, ลดขั้นตอนที่ไม่จำเป็น
2. **Bold clarity, not decoration** — สัสันผ่าน scale, สี copper, และ contrast ที่มีเหตุผล ไม่ใช่ effect สวยอย่างเดียว
3. **Scannable data** — ตาราง ฟอร์ม และสรุปตัวเลขอ่านได้ใน 2–3 วินาที; สถานะและยอดเงินต้องโดดเด่น
4. **Consistency across modules** — PO, invoice, cash ledger ฯลฯ ใช้ pattern เดียวกัน (nav, table, form, empty state)
5. **Trust through clarity** — ไม่ซ่อนข้อมูลสำคัญ; error/confirm state ชัด
6. **Warm construction identity** — ส้ม copper เป็น signal ของแบรนด์ ไม่ wallpaper ทั้งจอ

## Rollout Priority

อัปเดต UI **ทั้งระบบทีละโมดูล** — เริ่มจากหน้าแรก (index) → sign-in → purchase → invoice → อื่นๆ ตามลำดับ

## Accessibility & Inclusion

- เป้าหมาย **WCAG 2.1 AA**: contrast ข้อความ ≥4.5:1, focus state ชัด, ปุ่มและลิงก์มี label ที่เข้าใจได้
- **`prefers-reduced-motion` เข้มขึ้น** — ปิด shimmer, float, hover transform ที่ไม่จำเป็นเมื่อผู้ใช้ขอลด motion
- รองรับ keyboard navigation บน form และ action หลัก
- ข้อความไทยอ่านง่าย — line length และขนาดตัวอักษรเหมาะกับงาน office ทั้งวัน
- ฟอร์มบนมือถือ: input ≥16px

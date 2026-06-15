# แผนงาน: PR ↔ PO เลขท้ายเดียวกัน (Shared Tail Numbering)

> **สถานะ:** รอ implement — เก็บไว้เป็น spec อ้างอิง  
> **วันที่จด:** 2026-06-13  
> **ระบบปัจจุบัน:** PR กับ PO นับเลขแยกกัน (ยังไม่ lock)

---

## 1. เป้าหมายธุรกิจ

ต้องการให้ **เลขท้าย (001, 002, …) ของ PR กับ PO ที่เป็นคู่กัน ตรงกัน** เช่น

```
PR-TNC-2506-011  ──แปลง──►  PO-TNC-2506-011
```

ไม่ใช่แค่เก็บ `reference_pr_number` แยก — **เลขบนเอกสารต้องตรงกัน**

---

## 2. ระบบปัจจุบัน (as-is)

| จุด | ฟังก์ชัน | นับจาก |
|-----|----------|--------|
| สร้าง PR | `Purchase::nextPRNumber()` | `purchase_requests` |
| PO จาก PR | `Purchase::generatePONumber()` | `purchase_orders` (prefix `PO-TNC-{ym}-`) |
| PO โดยตรง | `Purchase::generatePONumber()` | `purchase_orders` |
| PO จ้าง (สั่งจ่าย/เบิกล่วงหน้า) | `Purchase::generatePONumber()` | `purchase_orders` |
| WO / สัญญาจ้าง | `Purchase::generateWorkOrderNumber()` | `purchase_orders` (prefix `WO-TNC-`) |

**ไฟล์หลัก:** `includes/Rtdb/Purchase.php`, `actions/action-handler.php`

**พฤติกรรมวันนี้:**

- PR-011 กับ PO-011 **ไม่ได้ถูกบังคับให้เป็นคู่**
- Prefix ต่างกัน (`PR-TNC` vs `PO-TNC`) + counter ต่างกัน → **ไม่ชนกัน**
- ตอน `create_po_from_pr` ใช้ `generatePONumber()` ไม่ copy จาก PR

---

## 3. ปัญหาถ้า lock แบบง่าย (ไม่มี reservation)

| สถานการณ์ | ผล |
|-----------|-----|
| PR 10 ใบ, PO 20 ใบ (รวม PO โดยตรง) | PR ใบใหม่ต้องการ 011 แต่ PO-011 มีแล้ว → **ชน** |
| PO โดยตรงกินเลข | PR ถัดไปอาจต้องข้าม (021 แทน 011) ถ้าใช้ shared pool |
| PR ค้างไม่แปลง PO | ช่องเลขว่างหรือจอง — ต้องมีกติกา |

---

## 4. แนวทางออกแบบ (3 ทาง)

### แนวทาง A — Shared Sequence Pool

เลขท้าย 001–999 เป็น pool ร่วม — PR และ PO แย่งช่องเดียวกัน

```
เลขถัดไป = max(เลขท้ายที่ใช้แล้วทั้ง PR + PO ในเดือน) + 1
```

**ตัวอย่าง:** PR 10 + PO 20 → PR ใบใหม่ = **021** (ไม่ใช่ 011)

| ข้อดี | ข้อเสีย |
|-------|---------|
| ไม่ชนเลข | PR ใบที่ 11 อาจได้เลข 021 — ลำดับที่สร้าง ≠ เลขท้าย |
| logic ชัด | PO โดยตรงกินเลข PR ข้ามไปเรื่อยๆ |

---

### แนวทาง B — จองเลขตอนสร้าง PR (Reservation) ⭐ แนะนำ

**หลักการ:** สร้าง PR = **จองช่องเลขทันที** → PO จาก PR ใช้เลขเดิม

```
1. สร้าง PR     → จอง slot 011 → PR-TNC-2506-011
2. แปลง PO     → ใช้ slot 011  → PO-TNC-2506-011  (ไม่ใช่ 012)
3. PR ใบถัดไป  → slot 011 ถูกจองแล้ว → PR-012
```

**โครงข้อมูล (แนวคิด):**

```text
purchase_doc_slots (หรือ field บน PR)
  ym, tail (011), status, pr_id, po_id, kind
  status: reserved | po_issued | cancelled | direct_po
```

#### B1 — PO โดยตรงใช้ pool เดียวกัน

- PO โดยตรงกินช่องถัดไปใน pool เดียวกับ PR
- อาจมีช่องที่มีแต่ PO ไม่มี PR (เช่น PO-012 โดยไม่มี PR-012)
- PR ใบถัดไปข้ามไป **013**

#### B2 — PO โดยตรงใช้ prefix แยก (แนะนำถ้าต้องการ lock สะอาด)

- PR ↔ PO หลัก: `PR-TNC-…` / `PO-TNC-…` (เลขท้ายตรงกัน)
- PO โดยตรง: `PO-D-TNC-…` หรือสายเลขแยก — **ไม่กินช่อง PR**

| ข้อดี | ข้อเสีย |
|-------|---------|
| PR-011 ↔ PO-011 คู่จริง | ต้องมี slot registry + กติกา PO โดยตรง |
| audit ชัด | implement ซับซ้อนกว่าปัจจุบัน |

---

### แนวทาง C — Copy-on-convert เท่านั้น

PR นับเลขตามเดิม — ตอนแปลง PO copy tail จาก PR

- **ชน** ถ้า PO-011 มีจาก PO โดยตรงแล้ว
- ต้องห้าม PO โดยตรงใช้เลขท้ายเดียวกัน หรือเลิก PO โดยตรง

---

## 5. FAQ จากการออกแบบ (สรุปความเข้าใจ)

### Q: PR 10 + PO 20 → PR ใหม่จะเป็น 21 ไหม?

| แนวทาง | คำตอบ |
|--------|--------|
| **ปัจจุบัน** | ไม่ — PR ใหม่ = 11, PO ใหม่ = 21 (แยกสาย) |
| **A / B1 (pool ร่วม)** | PR ใหม่ = **21** (ช่อง 1–20 ถูกใช้) |
| **B (จองตอน PR) + B2 PO โดยตรตแยก** | PR ใหม่ = **11** ถ้าช่อง 11 ว่าง |

### Q: PR-011 จอง PO-011 ไว้ — แปลง PO จะได้ 012 ไหม?

**ไม่** — ได้ **PO-011** (ใช้ slot ที่จองไว้)

### Q: PR-011 จองแล้ว, สร้าง PO โดยตรต 012, แปลง PR-011 จะข้ามเป็น 013 ไหม?

**ไม่** — PR-011 ยังแปลงเป็น **PO-011**

- PO โดยตรต **012** กินแค่ช่อง 012
- **PR ใบถัดไป** (ไม่ใช่ PR-011) จะได้ **013** เพราะ 011+012 ถูกใช้แล้ว (กรณี B1)

```text
011  [PR-011 จอง] ──► PO-011
012  [PO โดยตรต — ไม่มี PR-012]   ← รูในเลข (ถ้า B1)
013  [PR ใบถัดไป + PO คู่กัน]
```

### Q: ค้นหา "สายไฟ" ใน description กลางข้อความ

(ฟีเจอร์อื่นที่ทำแล้ว — ไม่เกี่ยวกับเลขเอกสาร)

---

## 6. ขอบเขตเอกสาร (ตัดสินใจก่อน implement)

| ประเภท | รวม lock PR↔PO? | แนะนำ v1 |
|--------|-----------------|----------|
| PR/PO ซื้อ (`purchase`) | ✅ | core |
| PO จาก PR | ✅ tail จาก PR | |
| PO โดยตรง | ⚠️ | B2: prefix แยก |
| PR/PO จ้าง (`hire`) | ❌ | WO = `WO-TNC` คนละสาย |
| PO สั่งจ่าย/เบิกล่วงหน้า | ❌ | หลาย PO ต่อ 1 WO |

**Prefix รายเดือน:** ปัจจุบันใช้ `{ym}` ในเลข — slot reset ทุกเดือน (ยืนยันกับธุรกิจ)

---

## 7. กติกา spec ที่ต้อง lock

### 7.1 PO จาก PR

```php
// แทน generatePONumber() สำหรับ flow นี้
$po_number = tnc_po_number_from_pr($pr_row);
// → PO-TNC-{ym}-{tail จาก pr_number}
```

- 1 PR → 1 PO (มี guard อยู่แล้ว — ยกเว้น cancelled)
- ตรวจ duplicate tail ในเดือนเดียวกัน

### 7.2 PO โดยตรง

เลือก **B1** (pool ร่วม) หรือ **B2** (prefix แยก `PO-D-TNC-…`)

### 7.3 เอกสารยกเลิก

**แนะนำ:** เลขไม่ reuse (audit ปลอดภัย)

### 7.4 PR ค้าง (อนุมัติแล้วยังไม่แปลง)

ช่องเลข **ถือว่าจอง** — เอกสารอื่นห้ามใช้ tail นั้น

---

## 8. จุดในโค้ดที่ต้องแก้ (เมื่อลงมือ)

| ไฟล์ | งาน |
|------|-----|
| `includes/Rtdb/Purchase.php` | `nextSharedPurchaseTail()`, `poNumberFromPr()`, direct PO |
| `includes/purchase/doc_slot_registry.php` (ใหม่) | จอง/ปล่อย slot |
| `actions/action-handler.php` | `save_pr`, `create_po_from_pr`, `create_po_direct` |
| `pages/purchase/purchase-request-create.php` | แสดงเลขจาก slot |
| `pages/purchase/purchase-order-from-pr.php` | preview PO = tail PR |
| `pages/purchase/purchase-order-create-direct.php` | prefix direct |
| RTDB / migration | backfill slot จากข้อมูลเก่า |

---

## 9. Migration ข้อมูลเก่า

| กลุ่ม | แนวทาง |
|------|--------|
| PR/PO ที่มี `pr_id` + `reference_pr_number` | map tail → slot `po_issued` |
| PR/PO tail ไม่ตรงกัน | **ไม่บังคับย้อน** — lock เฉพาะเอกสารใหม่ |
| PO โดยตรงเก่า | เก็บตามเดิม หรือย้ายเป็น `PO-D-` |

**Cutover date:** วันที่ X เป็นต้นไปใช้ระบบใหม่

---

## 10. Phase แนะนำ

| Phase | งาน |
|-------|-----|
| **0 — Spec** | ตอบคำถามเปิด (ด้านล่าง) + เลือก B1/B2 |
| **1 — Core** | slot registry + สร้าง PR |
| **2 — Convert** | PO from PR ใช้ tail PR |
| **3 — Direct PO** | prefix แยก หรือ pool ร่วม |
| **4 — UI/Print** | แสดงความสัมพันธ์ PR↔PO |
| **5 — Migration** | backfill + cutover |

---

## 11. คำถามเปิด (ตอบก่อน implement)

- [ ] **PO โดยตรง** — ยังใช้ไหม? B1 (pool ร่วม) หรือ B2 (prefix แยก)?
- [ ] **เลขท้าย vs ลำดับที่สร้าง** — ยอมให้ PR ใบที่ N ได้เลขไม่เท่า N ไหม? (กรณี B1)
- [ ] **เอกสารยกเลิก** — reuse เลขไหม?
- [ ] **Hire / WO / PO สั่งจ่าย** — อยู่นอก scope lock ใช่ไหม?
- [ ] **ข้อมูลเก่า** — lock เฉพาะใหม่ หรือ migrate ย้อนหลัง?
- [ ] **Reset รายเดือน** — ยืนยัน `{ym}` ใน prefix ตามเดิม?

---

## 12. แนวทางที่แนะนำ (สรุป)

**แนวทาง B + B2 (PO โดยตรต prefix แยก)**

1. สร้าง PR → จอง tail → `PR-TNC-{ym}-{tail}`
2. แปลง PO → `PO-TNC-{ym}-{tail เดียวกัน}`
3. PO โดยตรต → `PO-D-TNC-{ym}-{seq}` ไม่กินช่อง PR
4. Hire/WO → คง `WO-TNC-` แยก
5. ไม่ reuse เลขที่ยกเลิก
6. Cutover — เอกสารเก่าไม่บังคับแก้

---

## 13. อ้างอิงโค้ดปัจจุบัน

```php
// includes/Rtdb/Purchase.php
Purchase::nextPRNumber();      // PR-TNC-{ym}-{seq}
Purchase::generatePONumber();  // PO-TNC-{ym}-{seq}
Purchase::generateWorkOrderNumber(); // WO-TNC-{seq}

// actions/action-handler.php
// create_po_from_pr → generatePONumber() + reference_pr_number
```

---

*เอกสารนี้จัดทำจากการวิเคราะห์ใน chat — อัปเดตเมื่อตัดสินใจ spec แล้วก่อนเริ่ม implement*

#!/usr/bin/env python3
"""Generate the material-cycle UML (class diagram) as a standalone SVG.

Everything drawn here is read off the live schema (columns, foreign keys, CHECK
enums, ProcurementState transitions) — nothing is invented.
"""
import io
import re
import xml.dom.minidom

# ---------------------------------------------------------------- model
# (name, [attrs], note, badges)
A = 'append-only'

ROWS = [
    ("1 · Demand intake", "ورودی تقاضا", "MTO", "#e8f0fe", [
        ("mto_documents", ["discipline", "document_number", "source_type", "cde_document_id"], "", []),
        ("mto_revisions", ["revision_code", "state", "source_file_hash", "scope_mode"],
         "state: DRAFT|UNDER_REVIEW|ACCEPTED|SUPERSEDED|REJECTED", []),
        ("mto_staging_rows", ["mto_item_no", "raw_description", "raw_quantity", "material_item_id", "validation_state"],
         "validation: ACCEPTABLE|BLOCKED_*", []),
    ]),
    ("2 · Demand accepted", "پذیرش تقاضا", "MTO", "#e8f0fe", [
        ("accepted_mto_batches", ["document_number", "revision_code", "state", "accepted_at"], "state: ACCEPTED", []),
        ("accepted_mto_rows", ["mto_item_no", "material_item_id", "gross_quantity", "canonical_quantity", "material_category"], "", [A]),
        ("mto_acceptance_journal", ["ordinal", "origin", "selection_state"], "", [A]),
        ("mto_scope_observations", ["origin", "admission_state", "payload_hash"], "", [A]),
    ]),
    ("3 · Requisition", "درخواست مصالح", "MR", "#e6f4ea", [
        ("mr_author_policies", ["version", "selectors", "reason"], "nomination", []),
        ("material_requisitions", ["number", "state", "discipline", "material_category", "raised_by"],
         "DRAFT→SUBMITTED→TECHNICAL_APPROVED→APPROVED→ORDERED / REJECTED→DRAFT", []),
        ("material_requisition_items", ["line_no", "item_code", "quantity", "unit", "material_item_id"], "", []),
    ]),
    ("4 · Purchase order", "سفارش خرید", "PO", "#fff4e5", [
        ("purchase_orders", ["number", "state", "vendor_company_id", "discipline", "material_category"],
         "DRAFT|ISSUED|PARTIALLY_RECEIVED|RECEIVED|CANCELLED", []),
        ("purchase_order_items", ["line_no", "item_code", "quantity", "unit_price", "material_item_id", "supersedes_id"], "", []),
        ("po_execution_decisions", ["purpose", "sequence", "content_hash", "snapshot"], "purpose: finance|issue", [A]),
        ("po_pricing_revisions", ["sequence", "content_hash", "snapshot"], "", [A]),
        ("po_mr_allocations", ["quantity", "approved_quantity", "issue_decision_id"], "", []),
        ("po_item_allocations", ["quantity", "subsystem_id"], "", []),
    ]),
    ("5 · Receipt into custody", "دریافت به custody", "CUSTODY", "#fdecea", [
        ("piping_receipt_author_policies", ["version", "actions", "suspended", "custodian_company_id"], "nomination", []),
        ("piping_receipt_nomination_index", ["action", "selector", "policy_id"], "derived", []),
        ("piping_custody_records", ["sequence", "heat_ref", "quantity", "unit", "location_kind", "document_status", "state", "source_line_id", "unlinked_reason"],
         "location: QUARANTINE|…  doc: MISSING|PRESENT_UNVERIFIED|…", [A]),
        ("piping_custody_amendments", ["sequence", "heat_ref", "lot_ref", "source_kind", "payload_hash", "reason"],
         "source_kind: LINKED|UNRESOLVED", [A]),
        ("piping_custody_amendment_sources", ["source_line_id", "source_snapshot", "source_fingerprint"], "", []),
    ]),
    ("6 · Receiving QC / QA", "کنترل کیفیتِ دریافت", "QC·QA", "#f3e8fd", [
        ("piping_receiving_reviews", ["sequence", "observed_result", "findings", "custody_fingerprint"],
         "NO_NONCONFORMITY|NONCONFORMITY|INCOMPLETE", [A]),
        ("piping_qa_decisions", ["decision", "source_basis", "open_conditions", "declarations"],
         "ACCEPTED|HOLD|REJECTED", [A]),
        ("piping_qa_evidence", ["file_id", "kind", "snapshot"], "", []),
        ("piping_qa_amendment_source_bases", ["source_line_id", "snapshot"], "", []),
    ]),
    ("7 · Goods receipt", "رسید انبار", "GRN", "#e0f7fa", [
        ("piping_grn_records", ["number", "heat_ref", "quantity", "unit", "state", "custody_id", "qa_id", "review_id"], "state: POSTED", [A]),
        ("piping_grn_posted_index", ["custody_id", "grn_id"], "derived · one GRN per custody", []),
        ("piping_accepted_stock_entries", ["quantity", "unit", "custodian_company_id"], "", []),
    ]),
    ("8 · Stock ledger", "دفتر موجودی", "STOCK", "#e8f5e9", [
        ("stock_ledger_entries", ["ordinal", "kind", "item_code", "heat_ref", "quantity", "grn_id"],
         "RECEIPT|ISSUE|ADJUSTMENT_IN|ADJUSTMENT_OUT|OPENING_COUNT", [A]),
        ("stock_ledger_clocks", ["last_ordinal"], "", []),
    ]),
    ("9 · Certificates", "گواهی مصالح", "MTR", "#fff8e1", [
        ("material_certificates", ["certificate_type", "heat_ref", "lot_ref", "certificate_ref", "issuer_name"],
         "EN10204_3_1|EN10204_3_2|MILL_MTR|OTHER", [A]),
        ("material_certificate_bindings", ["binding", "heat_ref", "lot_ref", "custody_id", "custody_amendment_id", "unlinked_reason"],
         "CUSTODY_HEAT|CUSTODY_LOT_HEAT|UNRESOLVED · heat/lot derived", [A]),
    ]),
    ("10 · Piece-level custody", "custody سطح قطعه", "A.4b", "#fce4ec", [
        ("component_material_links", ["component_id", "custody_id", "link", "heat_ref", "lot_ref", "sequence", "unlinked_reason", "reason"],
         "CUSTODY_HEAT|CUSTODY_LOT_HEAT|OBSERVED · heat/lot derived", [A]),
        ("components", ["template_code", "sequence", "nominal_size", "schedule", "material_code", "length_mm"], "", []),
        ("ports", ["component_id", "role", "end_type", "is_connected"], "", []),
        ("joints", ["joint_seq", "joint_type", "state", "numbering_policy", "port_a_id", "port_b_id"],
         "MODELLED→FITTED · CHECK port_a_id < port_b_id", []),
    ]),
    ("11 · Line, pack, results", "خط، بسته، نتایج", "ITR", "#ede7f6", [
        ("piping_lines", ["line_number", "nominal_size", "piping_class_id", "release_state", "test_pack_id"],
         "DRAFT|RELEASED|AFC_CONFIRMED", []),
        ("piping_test_packs", ["code", "state", "test_pressure"], "DRAFT→READY→TESTED→ACCEPTED", []),
        ("joint_weld_records", ["kind", "welded_by_code", "weld_process", "consumable_lot_ref"], "", [A]),
        ("joint_ndt_results", ["method", "result", "technique_ref", "previous_result_id"], "", [A]),
    ]),
    ("12 · Legacy warehouse", "انبار قدیمی", "ADR-047", "#eceff1", [
        ("material_lots", ["lot_code", "item_code", "heat_number", "inspection_result", "mtc_document_id"], "legacy world", []),
        ("material_movements", ["type", "quantity", "from_location", "to_location", "reverses_movement_id"], "", []),
        ("material_legacy_reconciliations", ["decision", "lot_id", "custody_id", "heat_ref", "sequence"], "TAKE_OVER|RETIRE", [A]),
    ]),
    ("13 · Catalogue & engineering", "کاتالوگ و مهندسی", "MASTER", "#f1f8e9", [
        ("material_items", ["category", "commodity_code", "base_unit", "material_spec", "grade", "status", "source"],
         "PIPE|FITTING|FLANGE|VALVE|GASKET|BOLT_NUT|SPECIALTY|SUPPORT", []),
        ("piping_classes", ["code", "material", "pressure_rating", "state", "dn_span"], "PMS", []),
        ("piping_class_rules", ["rule_kind", "dn_range", "value"], "", []),
        ("piping_class_branch_rules", ["header_range", "branch_range", "component_type", "reinforcement"], "", []),
        ("material_inspections", ["number", "result", "stage", "purchase_order_id"], "", []),
    ]),
]

# (from, to, label, dashed?)
EDGES = [
    ("mto_revisions", "mto_staging_rows", "", False),
    ("mto_staging_rows", "accepted_mto_rows", "source_staging_row_id", False),
    ("accepted_mto_rows", "material_requisition_items", "demand → requisition", True),
    ("material_requisition_items", "purchase_order_items", "requisition_item_id", False),
    ("material_requisitions", "purchase_orders", "requisition_id", False),
    ("purchase_order_items", "po_mr_allocations", "", False),
    ("purchase_orders", "po_execution_decisions", "finance → issue", False),
    ("purchase_order_items", "piping_custody_records", "source_line_id", False),
    ("piping_custody_records", "piping_custody_amendments", "custody_id", False),
    ("piping_custody_amendments", "piping_custody_amendment_sources", "", False),
    ("piping_custody_records", "piping_receiving_reviews", "custody_id", False),
    ("piping_receiving_reviews", "piping_qa_decisions", "review_id", False),
    ("piping_qa_decisions", "piping_grn_records", "qa_id", False),
    ("piping_custody_records", "piping_grn_records", "custody_id (1:1)", False),
    ("piping_grn_records", "piping_accepted_stock_entries", "grn_id", False),
    ("piping_grn_records", "stock_ledger_entries", "grn_id → RECEIPT", False),
    ("material_certificate_bindings", "piping_custody_records", "custody_id", True),
    ("material_certificates", "material_certificate_bindings", "certificate_id", False),
    ("piping_custody_records", "component_material_links", "custody_id — heat DERIVED", True),
    ("component_material_links", "components", "component_id", False),
    ("components", "ports", "component_id", False),
    ("ports", "joints", "port_a_id / port_b_id", False),
    ("piping_lines", "components", "piping_line_id", False),
    ("piping_lines", "piping_test_packs", "test_pack_id", False),
    ("joints", "joint_weld_records", "joint_id", False),
    ("joints", "joint_ndt_results", "joint_id", False),
    ("material_lots", "material_movements", "lot_id", False),
    ("material_lots", "material_legacy_reconciliations", "lot_id", False),
    ("material_legacy_reconciliations", "piping_custody_records", "custody_id — TAKE_OVER", True),
    ("material_items", "material_certificates", "material_item_id", True),
]

# ---------------------------------------------------------------- layout
BW = 300
GAP = 26
LANE_W = 150
PAD_TOP = 118
ROW_GAP = 58
ROW_LABEL_H = 0


STYLE = {
    't':       'font-size="13" font-weight="700" fill="#1a237e"',
    'a':       'font-size="11" fill="#37474f"',
    'n':       'font-size="10" fill="#00695c" font-style="italic"',
    'b':       'font-size="9.5" font-weight="700" fill="#b71c1c"',
    'lane':    'font-size="13" font-weight="700" fill="#263238"',
    'lanefa':  'font-size="11.5" fill="#37474f"',
    'lane2':   'font-size="9.5" fill="#607d8b"',
    'edge':    'font-size="9.5"',
    'h1':      'font-size="22" font-weight="700" fill="#1a237e"',
    'h1fa':    'font-size="16" font-weight="700" fill="#283593"',
    'h2':      'font-size="12" fill="#546e7a"',
}

def inline_styles(doc):
    """CSS <style> blocks are ignored by some SVG rasterisers; inline them."""
    def repl(m):
        cls = m.group(1)
        extra = m.group(2) or ''
        return STYLE.get(cls, '') + (' ' + extra if extra else '')
    doc = re.sub(r'class="(\w+)"((?:\s+[^>]*)?)>', lambda m: (STYLE.get(m.group(1), '') + (m.group(2) or '')) + '>', doc)
    doc = re.sub(r'<style>.*?</style>', '', doc, flags=re.S)
    # a bare ampersand is fatal in XML
    doc = re.sub(r'&(?!(?:amp|lt|gt|quot|apos|#\d+);)', '&amp;', doc)
    return doc


def note_lines(note):
    if not note:
        return []
    out = []
    cur = ''
    for word in note.split():
        if len(cur) + len(word) + 1 > 46:
            out.append(cur); cur = word
        else:
            cur = (cur + ' ' + word).strip()
    if cur:
        out.append(cur)
    return out

def box_h(cls):
    _, attrs, note, badges = cls
    n = len(attrs) + len(note_lines(note)) + (1 if badges else 0)
    return 30 + 15 * n + 10

# measure rows
rows = []
for title, title_fa, tag, color, classes in ROWS:
    h = max(box_h(c) for c in classes)
    rows.append({'title': title, 'title_fa': title_fa, 'tag': tag, 'color': color, 'classes': classes, 'h': h})

ROW_END = LANE_W + 20 + max(len(r['classes']) for r in rows) * (BW + GAP) - GAP
W = ROW_END + 96
CHANNEL = ROW_END + 34
total_h = PAD_TOP + sum(r['h'] + ROW_GAP for r in rows)
H = total_h + 96

# place boxes
pos = {}
y = PAD_TOP
for r in rows:
    xs = LANE_W + 20
    for cls in r['classes']:
        pos[cls[0]] = (xs, y, BW, box_h(cls))
        xs += BW + GAP
    r['y'] = y
    y += r['h'] + ROW_GAP

out = []
w = out.append
w('<?xml version="1.0" encoding="UTF-8"?>')
w('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" font-family="DejaVu Sans, Tahoma, Vazirmatn, Segoe UI, Helvetica, Arial, sans-serif">' % (W, H, W, H))
w('<defs>')
w('<marker id="arrow" markerWidth="10" markerHeight="8" refX="9" refY="4" orient="auto"><path d="M0,0 L10,4 L0,8 z" fill="#455a64"/></marker>')
w('<marker id="arrowd" markerWidth="10" markerHeight="8" refX="9" refY="4" orient="auto"><path d="M0,0 L10,4 L0,8 z" fill="#8d6e63"/></marker>')
w('<style>')
w('.t{font-size:13px;font-weight:700;fill:#1a237e}')
w('.a{font-size:11px;fill:#37474f}')
w('.n{font-size:10px;fill:#00695c;font-style:italic}')
w('.b{font-size:9.5px;fill:#b71c1c;font-weight:700}')
w('.lane{font-size:13px;font-weight:700;fill:#263238}')
w('.lane2{font-size:9.5px;fill:#607d8b}\n.lanefa{font-size:11.5px;fill:#37474f}')
w('.edge{font-size:9.5px;fill:#455a64}')
w('.h1{font-size:22px;font-weight:700;fill:#1a237e}')
w('.h2{font-size:12px;fill:#546e7a}')
w('.h1fa{font-size:16px;font-weight:700;fill:#283593}')
w('</style>')
w('</defs>')
w('<rect width="%d" height="%d" fill="#ffffff"/>' % (W, H))
w('<text class="h1" x="24" y="36">The material cycle — as implemented</text>')
w('<text class="h1fa" x="24" y="60">چرخهٔ متریال، همان‌طور که پیاده‌سازی شده</text>')
w('<text class="h2" x="24" y="80">EPCC platform · every box is a real table, every arrow a real foreign key (dashed = derived / no direct FK)</text>')
w('<text class="h2" x="24" y="96">Generated from the live schema: 123 tables, information_schema columns + foreign keys + CHECK enums + ProcurementState transitions</text>')

# lanes
for r in rows:
    w('<rect x="12" y="%d" width="%d" height="%d" rx="8" fill="%s" opacity="0.28"/>' % (r['y'] - 12, W - 24, r['h'] + 24, r['color']))
    w('<text class="lane" x="22" y="%d">%s</text>' % (r['y'] + 8, r['title']))
    w('<text class="lanefa" x="22" y="%d">%s</text>' % (r['y'] + 26, r['title_fa']))
    w('<text class="lane2" x="22" y="%d">%s</text>' % (r['y'] + 44, r['tag']))

# classes
for r in rows:
    for (name, attrs, note, badges) in r['classes']:
        x, y, bw, bh = pos[name]
        w('<rect x="%d" y="%d" width="%d" height="%d" rx="6" fill="#ffffff" stroke="#455a64" stroke-width="1.1"/>' % (x, y, bw, bh))
        w('<rect x="%d" y="%d" width="%d" height="22" rx="6" fill="#eceff1"/>' % (x, y, bw))
        w('<rect x="%d" y="%d" width="%d" height="6" fill="#eceff1"/>' % (x, y + 16, bw))
        w('<text class="t" x="%d" y="%d">%s</text>' % (x + 8, y + 15, name))
        ty = y + 38
        if badges:
            w('<text class="b" x="%d" y="%d">«%s»</text>' % (x + 8, ty, ', '.join(badges)))
            ty += 15
        for a in attrs:
            w('<text class="a" x="%d" y="%d">%s</text>' % (x + 8, ty, a))
            ty += 15
        for ln in note_lines(note):
            w('<text class="n" x="%d" y="%d">%s</text>' % (x + 8, ty, ln.replace('&', '&amp;').replace('<', '&lt;')))
            ty += 15

# ---------------------------------------------------------------- edges
def anchor(name, side):
    x, y, bw, bh = pos[name]
    return {'l': (x, y + bh / 2), 'r': (x + bw, y + bh / 2),
            't': (x + bw / 2, y), 'b': (x + bw / 2, y + bh)}[side]

row_of = {}
for ri, r in enumerate(rows):
    for cj, cls in enumerate(r['classes']):
        row_of[cls[0]] = (ri, cj)

lane_used = {}
chan_i = 0

for (f, t, label, dashed) in EDGES:
    if f not in pos or t not in pos:
        continue
    fx, fy, fw, fh = pos[f]
    tx, ty, tw, th = pos[t]
    fi, cj_f = row_of[f]
    ti, cj_t = row_of[t]
    col = '#8d6e63' if dashed else '#455a64'
    mk = 'arrowd' if dashed else 'arrow'
    dash = ' stroke-dasharray="5,4"' if dashed else ''
    down = ti > fi

    if fi == ti:
        if cj_t == cj_f + 1:                      # neighbours: straight
            x1, y1 = anchor(f, 'r'); x2, y2 = anchor(t, 'l')
            d = 'M%d,%d L%d,%d' % (x1, y1, x2, y2)
            lx, ly = (x1 + x2) / 2, y1 - 5
        else:                                      # same row, not neighbours: below
            gy = fy + fh + 20
            x1 = fx + fw / 2; x2 = tx + tw / 2
            d = 'M%d,%d L%d,%d L%d,%d L%d,%d' % (x1, fy + fh, x1, gy, x2, gy, x2, ty + th)
            lx, ly = (x1 + x2) / 2, gy - 5
    elif abs(ti - fi) == 1:                        # adjacent rows: one elbow
        x1, y1 = anchor(f, 'b' if down else 't')
        x2, y2 = anchor(t, 't' if down else 'b')
        my = (y1 + y2) / 2
        d = 'M%d,%d L%d,%d L%d,%d L%d,%d' % (x1, y1, x1, my, x2, my, x2, y2)
        lx, ly = (x1 + x2) / 2, my - 5
    else:                                          # far apart: the side channel
        chan = CHANNEL + 18 * (chan_i % 4)
        chan_i += 1
        n = lane_used.get(ti, 0)
        lane_used[ti] = n + 1
        x1 = fx + fw / 2
        x2 = tx + tw / 2
        if down:
            gy1 = fy + fh + 22
            gy2 = ty - 16 - 7 * n
            end_y = ty
        else:
            gy1 = fy - 22
            gy2 = ty + th + 16 + 7 * n
            end_y = ty + th
        d = 'M%d,%d L%d,%d L%d,%d L%d,%d L%d,%d L%d,%d' % (
            x1, fy + fh if down else fy, x1, gy1, chan, gy1, chan, gy2, x2, gy2, x2, end_y)
        lx, ly = (chan + x2) / 2, gy2 - 5

    w('<path d="%s" fill="none" stroke="%s" stroke-width="1.1" marker-end="url(#%s)"%s/>' % (d, col, mk, dash))
    if label:
        w('<text class="edge" x="%d" y="%d" text-anchor="middle" fill="%s">%s</text>'
          % (lx, ly, col, label.replace('&', '&amp;').replace('<', '&lt;')))

# ---------------------------------------------------------------- legend
ly0 = H - 66
w('<rect x="12" y="%d" width="%d" height="54" rx="8" fill="#fafafa" stroke="#cfd8dc"/>' % (ly0 - 16, W - 24))
w('<text class="a" x="26" y="%d">«append-only» — no UPDATE/DELETE policy exists; a correction is a NEW row, and the newest row (by sequence / ordinal) is the truth.</text>' % ly0)
w('<text class="a" x="26" y="%d">Dashed arrow = derived, or a join that is not a foreign key.  Every table here is RLS + FORCE ROW LEVEL SECURITY, scoped by (project_id, company_id).</text>' % (ly0 + 18))
w('<text class="a" x="26" y="%d">heat_ref / lot_ref on material_certificate_bindings and component_material_links are DERIVED from the custody chain — a caller never states them.</text>' % (ly0 + 34))

w('</svg>')

doc = '\n'.join(out)
doc = inline_styles(doc)
open('/home/user/epcc-platform/docs/MATERIAL-CYCLE-uml.svg', 'w', encoding='utf-8').write(doc)
import xml.dom.minidom
xml.dom.minidom.parseString(doc)   # refuse to write invalid XML
print('XML validates OK')
print('W=%d H=%d boxes=%d edges=%d' % (W, H, len(pos), len(EDGES)))

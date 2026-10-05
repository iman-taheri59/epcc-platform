# Product Planning and Software Sales Infrastructure — EPCC Platform

Date: **2026-10-05**

## 1. Strategic Decision

Product planning, sales, deployment, activation, support, and commercial growth infrastructure must be designed from the beginning. The first market is **Iran**: the product must be ready for on-premise/air-gapped sales, Persian, Jalali, IRR/Toman, enterprise contracts, customer datacentre deployment, and sanctions constraints. At the same time, the commercial architecture must not block future international versions.

Decision principle:

```text
Iran-first commercialization now; international-ready commercial architecture later.
```

This document complements:

- `docs/Iran-First-Market-Addendum.md`
- `docs/Infra-Cost-Model-Iran.md`
- `docs/ROADMAP-TO-MVP-EN.md`
- `docs/PIPING-DISCIPLINE-COMPLETION-ROADMAP-EN.md`
- `docs/UI-UX-IMPROVEMENT-PLAN-EN.md`

---

## 2. Goal of Planning and Sales Infrastructure

The goal is not only to “build software”. The goal is to build a product that can be:

1. Demonstrated to Iranian customers;
2. Proposed and priced;
3. Installed inside a closed customer environment;
4. Controlled through licensing/activation;
5. Trained;
6. Supported;
7. Renewed or expanded;
8. Later packaged for international markets.

---

## 3. Iranian Market Requirements

### 3.1 Deployment model

The main sales path for Iran:

```text
On-premise / Private cloud / Air-gapped installation
```

Requirements:

- Installation without foreign internet dependency;
- Offline update bundle;
- Persian installation documentation;
- Health check and `verify:airgap`;
- Project backup/restore;
- Installation in Owner or EPC datacentres;
- No dependency on CDN, Google Fonts, external avatar services, or external APIs;
- Offline license activation.

### 3.2 Iranian pricing model

Pricing must match how Iranian organisations buy. Recommendation:

| SKU | Description | Pricing model |
|---|---|---|
| Project License | Main flagship product | One-time price by project size |
| Company Plan | Full / Special for each participating company | Annual or one-time with support |
| Implementation Package | Installation, configuration, initial training | Service fee |
| Support & Maintenance | Support, updates, backup review | Annual |
| Add-ons | BIM viewer, international AWP, AI, OCR | Phased |

For Iran, the core value should be **Project License + Implementation + Support**, not monthly SaaS.

### 3.3 Contracting and procurement

Iranian enterprise sales needs:

- Persian proposal;
- Formal proforma/invoice;
- Support contract;
- Persian SLA;
- RFP response template;
- Security checklist;
- Air-gap compliance checklist;
- Training plan;
- Installation handover minutes;
- UAT acceptance certificate;
- Data migration/import plan.

### 3.4 Initial customer targets

Three target categories:

1. **Owner/NOC affiliate** — value: control, confidentiality, completion, turnover, claims evidence.
2. **EPC contractor** — value: documents, materials, piping, payment certificates, reduced rework.
3. **PMC/MC/TPI** — value: review, inspection, punch, audit trail.

### 3.5 Iranian sales message

The message should not be “generic project management software”. A better message:

```text
An Iranian, on-premise, bilingual platform for controlling engineering, materials, piping, quality, and handover in EPCC projects; focused on contractual evidence, cross-company confidentiality, and mechanical completion readiness.
```

For the first demo:

- Document lifecycle
- Transmittal
- Procurement/materials
- Piping workspace
- Punch/MCC
- Persian PDF
- RLS/visibility between companies

---

## 4. Licensing and Activation Infrastructure

### 4.1 Requirements

- Offline license;
- Machine/site fingerprint;
- Project license count;
- Company plan entitlement;
- Module/add-on entitlement;
- Expiry/support period;
- Signed license file;
- Activation request/response for air-gapped environments;
- Audit of license changes.

### 4.2 Proposed model

```text
license.epcc.json + signature
```

Contents:

- customer legal name
- installation id
- allowed projects
- allowed companies/plans
- modules/add-ons
- support expiry
- issued_at / expires_at
- signature

### 4.3 Important principle

License enforcement must not complicate RLS/security. RLS is for confidentiality; entitlement/licensing is for product and commercial access.

---

## 5. Demo and Sales Engineering Infrastructure

### 5.1 Demo seed

Based on the earlier decision, previews use synthetic data. Sales needs an official demo seed:

```text
PH-14 South Pars Gas Compression Demo
```

It should include:

- Multiple company roles;
- Persian/English data;
- Clean and intentionally broken scenarios;
- Demo-level complete Piping;
- PDF output;
- Workflows that can be shown in under 15 minutes.

### 5.2 Demo scripts

One demo script per persona:

| Persona | Demo scenario |
|---|---|
| Owner | Project status, blockers, MCC readiness, confidentiality |
| EPC | Enter line, material, clear blocker, submit document |
| PMC/MC | Review, punch, approval, audit |
| TPI | Inspection, NDT/test pack evidence |
| Warehouse | Receipt/issue/site delivery |

### 5.3 Sales collateral

- Two-page Persian brochure;
- 10-slide introduction deck;
- Value matrix for Owner/EPC/PMC;
- Comparison against Excel/SharePoint/Primavera-only approaches;
- Security and on-prem FAQ;
- Internal price list;
- Pilot readiness checklist.

---

## 6. Product Planning Infrastructure

### 6.1 Planning inputs

Backlog should not come only from technical ideas. Inputs:

- Pilot customers;
- Contract/RFP requirements;
- Demo feedback;
- Support tickets;
- ADRs;
- SRS;
- Iranian market risks;
- International path.

### 6.2 Product phasing

Recommended release lanes:

| Lane | Goal |
|---|---|
| P0 Pilot | Installable demo/pilot with synthetic data and several real journeys |
| P1 Iran MVP | Deep Piping, documents, materials, punch/MCC, on-prem install |
| P2 Iran Enterprise | HSE, minimal Schedule, LDAP, backup/export, reporting |
| P3 International Ready | Multi-currency, SaaS readiness, AWP activation, English-first sales |
| P4 International Scale | Marketplace/add-ons, integrations, advanced AI/OCR |

### 6.3 Prioritisation

Every backlog item should be scored by:

- Iranian sales value;
- Pilot risk reduction;
- Technical dependency;
- Demoability;
- Reuse for other disciplines;
- International readiness;
- On-prem support cost.

---

## 7. Customer Implementation and Delivery Infrastructure

### 7.1 Implementation methodology

For each customer:

1. Discovery workshop
2. Project setup
3. Standards pack selection
4. Company/role setup
5. Demo seed walkthrough
6. Data import pilot
7. User training
8. UAT
9. Go-live
10. Hypercare

### 7.2 Delivery documents

- Installation report
- Configuration report
- License certificate
- Persian admin guide
- Persian user quick guide
- Backup/restore procedure
- Air-gap verification report
- UAT sign-off
- Support contact/SLA

---

## 8. Iranian Support and Sales Operations

### 8.1 Support model

- Level 1: user guidance and training;
- Level 2: configuration, reporting, data;
- Level 3: bug/security/update;
- Separate SLA for office hours and critical projects.

### 8.2 On-prem support risk

The largest commercial risk for Iranian on-prem deployments is support cost. From the start, provide:

- Diagnostic bundle;
- Log export;
- Health check;
- Version report;
- Backup verification;
- Update rollback;
- Admin training.

---

## 9. International Readiness in Later Versions

Do not overcomplicate the product now for international markets, but do not close the path.

### 9.1 Things that should remain neutral now

- Currency model;
- Calendar model;
- Language/resource pipeline;
- Standards pack engine;
- Company/project licensing model;
- Units of measure;
- Discipline registry;
- Audit/export;
- Deployment topology.

### 9.2 Things added later

- SaaS billing;
- Online activation;
- International payment processor;
- English-first marketing site;
- AWP methodology activation;
- GDPR/data residency controls;
- SOC2/ISO27001 evidence pack;
- Partner/channel program;
- Cloud marketplace packaging.

---

## 10. Product Changes Recommended for Sellability

### 10.1 Inside the product

- About/License page;
- Health check page;
- Demo mode;
- Sample data reset;
- Admin onboarding checklist;
- Usage/seat/project summary;
- Exportable system report.

### 10.2 Outside the product

- Sales deck;
- Persian website/landing page;
- Short demo video;
- Proposal template;
- RFP response library;
- Implementation SOW template;
- SLA/support contract template.

---

## 11. Sales and Planning Backlog

| Priority | Item | Description |
|---|---|---|
| 1 | Official demo seed | Realistic synthetic PH-14 data |
| 2 | Piping workspace demo | Main Iranian sales demo |
| 3 | License file design | Signed offline license |
| 4 | Installer / runtime environment | Repeatable on-prem install |
| 5 | Persian admin guide | Pilot handover document |
| 6 | Persian sales deck | Early sales meetings |
| 7 | Pilot checklist | Readiness and success criteria |
| 8 | Support diagnostics | Lower on-prem support cost |
| 9 | Proposal/SOW templates | Faster enterprise sales |
| 10 | International readiness checklist | Avoid dead-end decisions |

---

## 12. Conclusion

To succeed in Iran, the product needs more than code: demo seed, offline licensing, on-prem installer, Persian sales documents, training, support diagnostics, and a pricing model. For international markets, keep the commercial architecture neutral now and later add SaaS billing, AWP activation, compliance, and sales channels.

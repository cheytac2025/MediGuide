#!/usr/bin/env python3
"""Generate MediGuide STRIDE / PT PDF from evidence-based content. No secrets embedded."""

from __future__ import annotations

from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_JUSTIFY, TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import cm, mm
from reportlab.platypus import (
    KeepTogether,
    ListFlowable,
    ListItem,
    PageBreak,
    Paragraph,
    SimpleDocTemplate,
    Spacer,
    Table,
    TableStyle,
)
from reportlab.graphics.shapes import Drawing, Rect, String, Line

OUT = Path(__file__).with_name("MediGuide_STRIDE_Penetration_Test_Report.pdf")
PAGE_W, PAGE_H = A4


def styles():
    s = getSampleStyleSheet()
    s.add(ParagraphStyle(name="CoverTitle", parent=s["Title"], fontSize=22, leading=26, alignment=TA_CENTER, spaceAfter=12))
    s.add(ParagraphStyle(name="CoverSub", parent=s["Normal"], fontSize=12, leading=16, alignment=TA_CENTER, spaceAfter=6))
    s.add(ParagraphStyle(name="H1", parent=s["Heading1"], fontSize=14, leading=18, spaceBefore=14, spaceAfter=8, textColor=colors.HexColor("#0f3d5c")))
    s.add(ParagraphStyle(name="H2", parent=s["Heading2"], fontSize=11.5, leading=14, spaceBefore=10, spaceAfter=6, textColor=colors.HexColor("#1a5276")))
    s.add(ParagraphStyle(name="Body", parent=s["Normal"], fontSize=9.5, leading=12.5, alignment=TA_JUSTIFY, spaceAfter=6))
    s.add(ParagraphStyle(name="Small", parent=s["Normal"], fontSize=8.5, leading=11, spaceAfter=4))
    s.add(ParagraphStyle(name="TableCell", parent=s["Normal"], fontSize=7.5, leading=9.5))
    s.add(ParagraphStyle(name="Footer", parent=s["Normal"], fontSize=8, alignment=TA_CENTER))
    s.add(ParagraphStyle(name="Meta", parent=s["Normal"], fontSize=9, leading=12, alignment=TA_LEFT, spaceAfter=3))
    return s


def footer(canvas, doc):
    canvas.saveState()
    canvas.setStrokeColor(colors.HexColor("#0f3d5c"))
    canvas.setLineWidth(0.6)
    canvas.line(1.8 * cm, 1.4 * cm, PAGE_W - 1.8 * cm, 1.4 * cm)
    canvas.setFont("Helvetica", 8)
    canvas.setFillColor(colors.HexColor("#333333"))
    canvas.drawString(1.8 * cm, 0.9 * cm, "MediGuide STRIDE / Controlled PT Report v1.3 — Confidential Capstone")
    canvas.drawRightString(PAGE_W - 1.8 * cm, 0.9 * cm, f"Page {doc.page}")
    canvas.restoreState()


def table(data, col_widths=None):
    t = Table(data, colWidths=col_widths, repeatRows=1)
    t.setStyle(
        TableStyle(
            [
                ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#0f3d5c")),
                ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
                ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
                ("FONTSIZE", (0, 0), (-1, -1), 7.5),
                ("LEADING", (0, 0), (-1, -1), 9.5),
                ("GRID", (0, 0), (-1, -1), 0.3, colors.HexColor("#99aab5")),
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
                ("LEFTPADDING", (0, 0), (-1, -1), 4),
                ("RIGHTPADDING", (0, 0), (-1, -1), 4),
                ("TOPPADDING", (0, 0), (-1, -1), 3),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
                ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, colors.HexColor("#f4f7fa")]),
            ]
        )
    )
    return t


def p(text, style):
    return Paragraph(text.replace("\n", "<br/>"), style)


def architecture_drawing():
    d = Drawing(460, 210)
    d.add(Rect(0, 0, 460, 210, fillColor=colors.HexColor("#f8fafc"), strokeColor=colors.HexColor("#d0d7de")))
    boxes = [
        (20, 160, 90, 30, "Browsers"),
        (150, 160, 110, 30, "Nginx TLS"),
        (300, 150, 140, 50, "Laravel / PHP-FPM"),
        (20, 80, 100, 35, "MySQL 8"),
        (150, 80, 100, 35, "Gmail SMTP"),
        (280, 80, 120, 35, "Claude API"),
        (150, 20, 160, 35, "Audit + Integrity"),
    ]
    for x, y, w, h, label in boxes:
        d.add(Rect(x, y, w, h, fillColor=colors.HexColor("#e8f1f8"), strokeColor=colors.HexColor("#0f3d5c"), strokeWidth=1))
        d.add(String(x + w / 2, y + h / 2 - 3, label, fontSize=8, fillColor=colors.HexColor("#0f3d5c"), textAnchor="middle"))
    # connectors
    for x1, y1, x2, y2 in [
        (110, 175, 150, 175),
        (260, 175, 300, 175),
        (370, 150, 70, 115),
        (370, 150, 200, 115),
        (370, 150, 340, 115),
        (370, 150, 230, 55),
    ]:
        d.add(Line(x1, y1, x2, y2, strokeColor=colors.HexColor("#5d6d7e"), strokeWidth=0.8))
    d.add(String(230, 198, "Figure 1. Logical system architecture", fontSize=8, fillColor=colors.HexColor("#333333"), textAnchor="middle"))
    return d


def severity_chart():
    # Simple bar chart: Critical 0, High 2, Medium 3, Low 3
    d = Drawing(420, 140)
    d.add(String(210, 125, "Figure 2. Confirmed finding severity distribution", fontSize=8, fillColor=colors.HexColor("#333333"), textAnchor="middle"))
    data = [("Critical", 0, colors.HexColor("#7b241c")), ("High", 2, colors.HexColor("#c0392b")), ("Medium", 3, colors.HexColor("#d68910")), ("Low", 3, colors.HexColor("#2471a3"))]
    max_v = 3
    base_y = 25
    for i, (label, val, col) in enumerate(data):
        x = 50 + i * 90
        h = (val / max_v) * 80 if max_v else 0
        d.add(Rect(x, base_y, 40, max(h, 1 if val == 0 else h), fillColor=col, strokeColor=colors.HexColor("#333333"), strokeWidth=0.5))
        d.add(String(x + 20, base_y - 12, label, fontSize=8, textAnchor="middle"))
        d.add(String(x + 20, base_y + max(h, 1) + 4, str(val), fontSize=8, textAnchor="middle"))
    return d


def build():
    st = styles()
    story = []

    # Cover
    story.append(Spacer(1, 2.2 * cm))
    story.append(p("MEDIGUIDE", st["CoverTitle"]))
    story.append(p("STRIDE Threat Modeling and Controlled Penetration Test Report", st["CoverSub"]))
    story.append(Spacer(1, 0.6 * cm))
    story.append(p("AI-Powered Hospital Navigation and Appointment Management System", st["CoverSub"]))
    story.append(Spacer(1, 1.2 * cm))
    meta = [
        ["Field", "Value"],
        ["Document version", "1.3 (checkpoint done; Nginx pending sudo) · baselines preserved"],
        ["Report date", "21 November 2026"],
        ["Target URL", "https://qmhchospital.com"],
        ["Host / DB", "srv2179431 / mediguide_demo"],
        ["Production Git", "726d516cefc98e9c3595ef6c906f982545b0a8c2"],
        ["Deployed packages", "Laravel 13.40.0 · commonmark 2.10.2 · Symfony HF/serializer 7.4.23"],
        ["Classification", "Capstone / Internal Security Assessment"],
    ]
    story.append(table([[Paragraph(c, st["TableCell"]) for c in row] for row in meta], [4.5 * cm, 11.5 * cm]))
    story.append(Spacer(1, 1.2 * cm))
    story.append(p("<b>Disclaimer:</b> This assessment is evidence-based and controlled. It is not a certification that the system is free of vulnerabilities. Live and isolated evidence are distinguished. Secrets, OTPs, tokens, cookies, and patient clinical content are excluded.", st["Body"]))
    story.append(PageBreak())

    # 1 Executive summary
    story.append(p("1. Executive Summary", st["H1"]))
    story.append(p("MediGuide was assessed using Microsoft STRIDE threat modeling, OWASP-aligned review, composer dependency auditing, isolated PHPUnit security suites, and low-impact read-only production verification under explicit rules of engagement.", st["Body"]))
    story.append(table(
        [[Paragraph(c, st["TableCell"]) for c in r] for r in [
            ["Metric", "Result"],
            ["Security-focused PHPUnit", "293 passed / 2,265 assertions"],
            ["Full PHPUnit regression", "1,143 passed / 7,960 assertions"],
            ["Four-role live email OTP", "PASS (users 33, 35, 36, 37)"],
            ["Strict integrity", "PASS (11 appointments, 5 patients)"],
            ["Audit chain", "PASS (105/105 valid; checkpoint seq 54, lag 51)"],
            ["Confirmed exploitable app defects under RoE", "None demonstrated"],
            ["Confirmed findings", "8 (dependency, headers, operational)"],
            ["Capstone demo security posture", "ACCEPTABLE with residual risks"],
            ["Mandatory fleet OTP readiness", "NOT READY"],
        ]],
        [7 * cm, 9 * cm],
    ))
    story.append(Spacer(1, 0.3 * cm))
    story.append(severity_chart())
    story.append(PageBreak())

    # 2-5
    story.append(p("2. Project and System Overview", st["H1"]))
    story.append(p("MediGuide provides AI-assisted hospital navigation and appointment workflows for Patient, Hospital Staff, Doctor, and IT Administrator roles. Production controls include HTTPS, Fortify authentication (password, TOTP, passkeys), account-scoped email OTP, invitation activation, RBAC, department/clinic scoping, CSRF, encrypted sensitive fields, HMAC integrity, and a tamper-evident audit chain.", st["Body"]))

    story.append(p("3. Assessment Objectives", st["H1"]))
    for item in [
        "Model threats with STRIDE across auth, clinical, AI, mail, and audit surfaces.",
        "Validate access control, authentication, integrity, and logging with isolated tests.",
        "Perform non-destructive live checks (HTTP, headers, integrity, audit, config).",
        "Identify confirmed weaknesses, operational risks, and untested areas.",
        "Deliver a capstone-ready PDF and Markdown report with sanitized evidence.",
    ]:
        story.append(p(f"• {item}", st["Body"]))

    story.append(p("4. Scope and Rules of Engagement", st["H1"]))
    story.append(p("<b>In scope:</b> application source/config, isolated fixtures, read-only production verification, HTTPS header inspection, composer advisories, isolated AI safety tests.", st["Body"]))
    story.append(p("<b>Out of scope without approval:</b> destructive/high-volume attacks, credential spraying, patient data extraction, production writes, unapproved emails/Anthropic calls, Nginx/firewall/auth-flag/key changes.", st["Body"]))

    story.append(p("5. Testing Methodology", st["H1"]))
    story.append(p("Baseline acceptance evidence was independently rechecked where possible. STRIDE modeling identified assets and trust boundaries. OWASP Top 10 / WSTG / ASVS expectations guided reviews. Isolated suites exercised authorization, OTP, invitations, integrity, audit, and AI safety. Live checks remained read-only. PASS requires evidence; otherwise INCONCLUSIVE or NOT TESTED.", st["Body"]))
    story.append(PageBreak())

    # Architecture
    story.append(p("6. System Architecture", st["H1"]))
    story.append(architecture_drawing())
    story.append(Spacer(1, 0.3 * cm))
    story.append(p("7. Data Flows and Trust Boundaries", st["H1"]))
    story.append(table(
        [[Paragraph(c, st["TableCell"]) for c in r] for r in [
            ["Boundary", "Description"],
            ["TB1", "Internet ↔ Nginx TLS"],
            ["TB2", "Unauthenticated ↔ Authenticated session"],
            ["TB3", "Role boundaries (Patient/Staff/Doctor/Admin)"],
            ["TB4", "Application ↔ MySQL"],
            ["TB5", "Application ↔ Gmail SMTP"],
            ["TB6", "Application ↔ Anthropic Claude API"],
            ["TB7", "Audit checkpoint store outside web root"],
        ]],
        [3 * cm, 13 * cm],
    ))
    story.append(PageBreak())

    # STRIDE matrix
    story.append(p("8. STRIDE Threat Matrix", st["H1"]))
    stride_rows = [
        ["ID", "Cat", "Threat", "Control / Result", "Residual"],
        ["S-01", "S", "Password without 2FA", "Account OTP for pilots; TOTP available", "Global OTP off"],
        ["S-02", "S", "OTP replay/guess", "Hash/consume/lock/TTL PASS", "Low"],
        ["S-03", "S", "Invitation reuse", "Hash/single-use/expiry PASS", "Email channel"],
        ["S-04", "S", "Session hijack", "Secure+HttpOnly+SameSite", "CSP gap"],
        ["T-01", "T", "Record tamper", "HMAC integrity LIVE PASS", "Checkpoint lag"],
        ["T-02", "T", "Param/mass assign", "Form requests + RBAC", "Residual"],
        ["R-01", "R", "Deny actions", "Audit events + chain", "Tail uncovered"],
        ["R-02", "R", "Audit deletion", "HMAC + checkpoint", "Lag=51"],
        ["I-01", "I", "Cross-role data", "RBAC/dept scope PASS", "Live IDOR fuzz NT"],
        ["I-02", "I", "Debug/secrets", "DEBUG=false; mode 640", "Server banner"],
        ["I-03", "I", "OTP in logs/audit", "Scrub tests + scan clean", "Monitor"],
        ["D-01", "D", "Auth flooding", "Throttles configured", "No stress test"],
        ["D-02", "D", "Parser DoS deps", "Advisories confirmed", "Patch needed"],
        ["E-01", "E", "Role escalation", "RBAC + registration tests", "Regression"],
        ["E-02", "E", "Cross-department", "Staff scope suite PASS", "—"],
        ["E-03", "E", "Unauthorized admin", "No public admin create", "Ops process"],
    ]
    story.append(table([[Paragraph(c, st["TableCell"]) for c in r] for r in stride_rows], [1.4*cm, 1.1*cm, 3.6*cm, 5.5*cm, 3.4*cm]))
    story.append(PageBreak())

    # OWASP
    story.append(p("9. OWASP Security Assessment", st["H1"]))
    owasp = [
        ["OWASP area", "Result", "Notes"],
        ["A01 Broken Access Control", "PASS*", "Isolated RBAC/scope + live 302; *live IDOR fuzz NOT TESTED"],
        ["A02 Cryptographic Failures", "PASS", "HTTPS; hashed OTP/invites; encrypted instructions tests"],
        ["A03 Injection", "PASS*", "Eloquent/XSS encoding tests; *no intrusive prod payloads"],
        ["A04 Insecure Design", "PASS w/ notes", "Passkey bypasses email OTP by design"],
        ["A05 Misconfiguration", "PARTIAL FAIL", "Missing HSTS/CSP; DEBUG off PASS"],
        ["A06 Vulnerable Components", "FAIL", "composer audit advisories on locked packages"],
        ["A07 Auth Failures", "PASS*", "OTP/TOTP/invite suites + live pilots; *fleet MFA incomplete"],
        ["A08 Integrity Failures", "PASS", "HMAC + audit chain"],
        ["A09 Logging/Monitoring", "PASS w/ gap", "Chain valid; checkpoint lag 51"],
        ["A10 SSRF", "NOT TESTED", "No approved harness"],
    ]
    story.append(table([[Paragraph(c, st["TableCell"]) for c in r] for r in owasp], [4.5*cm, 2.8*cm, 8.7*cm]))
    story.append(PageBreak())

    # PT matrix
    story.append(p("10. Penetration Test Case Matrix", st["H1"]))
    story.append(p("Environment: I=Isolated, L=Live read-only, R=Review. Intrusive production exploitation was not performed.", st["Small"]))
    pt = [
        ["ID", "STRIDE", "Env", "Result", "Evidence"],
        ["PT-001", "S OTP replay/expiry", "I", "PASS", "EmailOtpChallengeTest"],
        ["PT-002", "S OTP lockout", "I", "PASS", "EmailOtpChallengeTest"],
        ["PT-003", "S OTP fail-closed mail", "I", "PASS", "EmailOtpChallengeTest"],
        ["PT-004", "S Session regenerate", "I", "PASS", "EmailOtpChallengeTest"],
        ["PT-005", "S Four-role OTP", "I+L", "PASS", "Tests + live pilots"],
        ["PT-006", "S Invite reuse/expiry", "I", "PASS", "AdminUserAccountTest"],
        ["PT-007", "S Password reset token", "I", "PASS", "PasswordResetTest"],
        ["PT-008", "S Login throttle cfg", "R", "PASS", "FortifyServiceProvider 5/min"],
        ["PT-009", "T Integrity HMAC", "L", "PASS", "integrity-verify --strict"],
        ["PT-010", "T Consult integrity", "I", "PASS", "ConsultationDocumentationTest"],
        ["PT-011", "R Audit tamper", "I+L", "PASS", "AuditTrailTest + audit-verify"],
        ["PT-012", "R Checkpoint", "I", "PASS", "AuditCheckpointTest"],
        ["PT-013", "R Sensitive audit scrub", "I", "PASS", "AuditTrailTest"],
        ["PT-014", "I XSS encoding", "I", "PASS", "AiSuggestedDoctorsTest"],
        ["PT-015", "I Debug/secret perms", "L", "PASS", "DEBUG=false; 640"],
        ["PT-016", "I OTP not in audit", "L", "PASS", "Post-pilot scan"],
        ["PT-017", "I Security headers", "L", "FAIL", "Missing HSTS/CSP"],
        ["PT-018", "D OTP cooldown", "I", "PASS", "EmailOtpChallengeTest"],
        ["PT-019", "D Dep DoS advisories", "R", "FAIL", "composer audit"],
        ["PT-020", "E Cross-role staff", "I", "PASS", "HospitalStaffDepartmentScopeTest"],
        ["PT-021", "E Dept isolation", "I", "PASS", "HospitalStaffDepartmentScopeTest"],
        ["PT-022", "E Reg role assign", "I", "PASS", "AdminDashboardTest"],
        ["PT-023", "E Unauth dashboards", "L", "PASS", "HTTP 302"],
        ["PT-024", "E Admin create UI", "R+L", "PASS", "No store-admin route"],
        ["PT-025", "I/E AI safety", "I", "PASS", "AiUrgent/Safety suites"],
        ["PT-026", "Inj live payloads", "—", "NOT TESTED", "RoE"],
        ["PT-027", "D Stress/flood", "—", "NOT TESTED", "RoE"],
        ["PT-028", "SSRF", "—", "NOT TESTED", "No harness"],
    ]
    story.append(table([[Paragraph(c, st["TableCell"]) for c in r] for r in pt], [1.6*cm, 3.6*cm, 1.4*cm, 2.2*cm, 6.2*cm]))
    story.append(Spacer(1, 0.2 * cm))
    story.append(p("Baseline assessment suites: <b>293 passed</b> security-focused · <b>1,143 passed</b> full. Pre-deploy hardening suites: <b>395 passed</b> focused · <b>1,145 passed</b> full.", st["Body"]))
    story.append(PageBreak())

    # Live snapshot
    story.append(p("11. Live Verification Snapshot", st["H1"]))
    live = [
        ["Check", "Value"],
        ["Git", "726d516cefc98e9c3595ef6c906f982545b0a8c2"],
        ["HOME / LOGIN", "HTTP 200 / 200"],
        ["APP_DEBUG", "false"],
        ["Session", "database; Secure; HttpOnly; SameSite=lax"],
        ["Present headers", "X-Frame-Options, X-Content-Type-Options, Referrer-Policy"],
        ["Missing headers", "HSTS + CSP-RO (approved; sudo blocked)"],
        ["Server banner", "nginx/1.24.0 (Ubuntu) — FIND-003 pending sudo"],
        ["OTP flags", "EMAIL_OTP=true; ALL_ROLES=false"],
        ["OTP opt-ins", "33,35,36,37 only"],
        ["Integrity", "11 appt / 5 patient valid"],
        ["Audit", "111/111; checkpoint 111; lag 0"],
        ["composer audit (VPS)", "0 advisories"],
        ["Services", "php-fpm, queue, nginx active"],
        ["File modes", "config.php 640 mediguide:www-data"],
        ["Clinic UI filter", "4 exclusion phrases: 0 public hits; DB preserved"],
    ]
    story.append(table([[Paragraph(c, st["TableCell"]) for c in r] for r in live], [5*cm, 11*cm]))
    story.append(p("Cookie material observed during header inspection was discarded and is not reproduced.", st["Small"]))
    story.append(PageBreak())

    # Findings
    story.append(p("12. Confirmed Vulnerabilities and Weaknesses", st["H1"]))
    findings = [
        ("FIND-001 Missing HSTS", "Medium", "Nginx headers", "S/T", "A05",
         "Live /login headers lacked Strict-Transport-Security.",
         "Add HSTS after confirming HTTPS-only. CVSS 5.3 (C:L)."),
        ("FIND-002 Missing CSP", "Medium", "HTTP headers", "I/S", "A05",
         "No Content-Security-Policy header observed.",
         "Deploy Vite/Inertia-compatible CSP."),
        ("FIND-003 Server version disclosure", "Low", "Nginx Server header", "I", "A05",
         "Server: nginx/1.24.0 (Ubuntu).",
         "server_tokens off;"),
        ("FIND-004 league/commonmark advisories", "High", "composer lock 2.10.1", "D/I", "A06",
         "GHSA-3q6v-r5mr-hxv8 (high DoS), GHSA-97jj-33gv-5xf9 (medium).",
         "Upgrade beyond 2.10.1; retest markdown rendering."),
        ("FIND-005 Laravel task output injection", "Low", "laravel/framework 13.32.0", "T", "A06",
         "CVE-2026-128083 affects <13.40.0.",
         "Upgrade to >=13.40.0 when approved."),
        ("FIND-006 Symfony http-foundation/yaml advisories", "Med–High", "symfony/* 8.0.15", "S/D", "A06",
         "Multiple CVEs via composer audit on locked 8.0.15.",
         "Upgrade via Laravel-compatible releases."),
        ("FIND-007 Audit checkpoint lag", "Medium", "Audit checkpoint", "R", "A09",
         "Checkpoint seq 54; 51 later events uncovered.",
         "Approved checkpoint refresh + encrypted backup."),
        ("FIND-008 Fleet email/MFA unreadiness", "High (ops)", "Account hygiene", "S", "A07",
         "Only 4 OTP-ready; 26 unverified; global OTP disabled.",
         "Remediate inboxes before mandatory OTP."),
    ]
    for title, sev, comp, stride, owasp, evidence, rem in findings:
        block = [
            p(f"<b>{title}</b> — Severity: {sev}", st["H2"]),
            p(f"Component: {comp} · STRIDE: {stride} · OWASP: {owasp}", st["Small"]),
            p(f"<b>Evidence:</b> {evidence}", st["Body"]),
            p(f"<b>Remediation:</b> {rem}", st["Body"]),
        ]
        story.append(KeepTogether(block))

    story.append(PageBreak())
    story.append(p("13. Potential Threats, Operational Risks, Untested Areas", st["H1"]))
    story.append(p("<b>Potential threats:</b> passkey path bypasses email OTP by design; email-channel compromise; live horizontal IDOR fuzz not performed.", st["Body"]))
    story.append(p("<b>Operational risks:</b> synthetic pilots remain OTP-enabled; backup age vs later auth events; no failure-storm validation.", st["Body"]))
    story.append(p("<b>Untested:</b> production intrusive injection, SSRF, ASVS L2 full checklist, live Anthropic prompt-injection, social engineering.", st["Body"]))

    story.append(p("14. Existing Controls Validated", st["H1"]))
    story.append(p("HTTPS, CSRF, RBAC, department scope, email OTP, invitations, HMAC integrity, audit chain/checkpoint crypto, auth throttles, APP_DEBUG=false, secret file modes, and isolated AI safety detectors were validated with the evidence cited above.", st["Body"]))

    story.append(p("15. Remediation Priorities", st["H1"]))
    story.append(p("<b>P0:</b> None confirmed.", st["Body"]))
    story.append(p("<b>P1:</b> Complete inbox remediation before mandatory OTP (FIND-008).", st["Body"]))
    story.append(p("<b>P2:</b> HSTS, CSP Report-Only, checkpoint refresh (FIND-001/002/007).", st["Body"]))
    story.append(p("<b>P3:</b> Hide Nginx version; pilot cleanup; keep encrypted backups current.", st["Body"]))
    story.append(p("Dependency findings FIND-004/005/006 were deployed to production at 3df7c9e. Nginx and checkpoint fixes remain undeployed.", st["Body"]))

    story.append(p("16. Retest Results", st["H1"]))
    story.append(p("Production dependency deploy verified: Laravel 13.40.0, commonmark 2.10.2, Symfony http-foundation/serializer 7.4.23; composer audit clean; clinic exclusion phrases filtered from public display; integrity/audit PASS. Nginx headers and checkpoint refresh still require separate approval.", st["Body"]))

    story.append(p("17. Residual Risks", st["H1"]))
    story.append(p("Incomplete header hardening, checkpoint lag (57 events), incomplete fleet MFA readiness, pending interactive PHP-FPM reload confirmation, and untested SSRF/injection/stress surfaces remain.", st["Body"]))

    story.append(p("18. Security Readiness Conclusion", st["H1"]))
    conclusion = [
        ["Question", "Verdict"],
        ["Capstone demonstration security posture", "ACCEPTABLE with documented residual risks"],
        ["Fully secure / PT certified", "NO — not claimed"],
        ["Broad hospital mandatory OTP readiness", "NOT READY"],
        ["Critical exploitable findings under RoE", "None confirmed"],
    ]
    story.append(table([[Paragraph(c, st["TableCell"]) for c in r] for r in conclusion], [8*cm, 8*cm]))

    story.append(PageBreak())
    story.append(p("19. References", st["H1"]))
    for ref in [
        "Microsoft — The STRIDE Threat Model",
        "OWASP Top 10 (2021); OWASP WSTG; OWASP ASVS",
        "FIRST — CVSS v3.1",
        "Laravel Fortify documentation",
        "Composer / GitHub Advisory Database",
        "MediGuide Final Capstone System Acceptance Report (baseline)",
    ]:
        story.append(p(f"• {ref}", st["Body"]))

    story.append(p("20. Appendices", st["H1"]))
    story.append(p("<b>Appendix A — Evidence index:</b> E-PHPUNIT-SEC (293/395), E-PHPUNIT-FULL (1143/1145), E-LIVE-HEADERS, E-LIVE-INTEGRITY, E-LIVE-AUDIT, E-COMPOSER, E-PROD-DEPLOY-3df7c9e, E-OTP-PILOTS.", st["Body"]))
    story.append(p("<b>Appendix B — Locked packages (deployed):</b> laravel/framework v13.40.0; league/commonmark 2.10.2; symfony/http-foundation v7.4.23; symfony/serializer v7.4.23; symfony/yaml v7.4.23 (require-dev only).", st["Body"]))
    story.append(p("<b>Appendix C — Change log:</b> v1.0 initial STRIDE/PT; v1.1 local dependency remediation; v1.2 production deploy of deps + clinic UI cleanup.", st["Body"]))
    story.append(PageBreak())
    story.append(p("21. Post-STRIDE Hardening Update (v1.3)", st["H1"]))
    story.append(p("Baseline v1.0 findings are preserved. v1.3 records checkpoint refresh completion and Nginx apply blocked on interactive sudo.", st["Body"]))
    rem = [
        ["Finding", "Action", "Prod status"],
        ["FIND-004 commonmark", "2.10.1 → 2.10.2", "Resolved at 3df7c9e"],
        ["FIND-005 Laravel", "13.32.0 → 13.40.0 (path unused)", "Resolved at 3df7c9e"],
        ["FIND-006 Symfony", "HF/serializer 7.4.23; yaml 7.4.23 locked", "Resolved at 3df7c9e"],
        ["composer audit", "Clean (0 advisories)", "Verified on VPS"],
        ["Clinic UI phrases", "Display filter for 4 exclusion phrases", "Verified 0 public hits"],
        ["FIND-007 checkpoint", "audit-checkpoint --execute → seq 111", "Resolved (lag 0)"],
        ["FIND-001 HSTS", "Snippet + apply script ready", "Pending interactive sudo"],
        ["FIND-002 CSP-RO", "Report-Only only (not enforce)", "Pending interactive sudo"],
        ["FIND-003 server_tokens", "http { server_tokens off; }", "Pending interactive sudo"],
        ["FIND-008 inbox", "Remediation plan only", "Open"],
    ]
    story.append(table([[Paragraph(c, st["TableCell"]) for c in r] for r in rem], [4.2*cm, 7.3*cm, 4.5*cm]))
    story.append(Spacer(1, 0.25 * cm))
    story.append(p("Audit chain 111/111 valid; checkpoint protection verified; encrypted pre/post checkpoint backups stored under /home/mediguide/secure-backups/phase4j/. CSP enforcement is <b>not</b> complete merely because Report-Only is prepared.", st["Body"]))
    story.append(p("<b>Updated verdict:</b> Capstone demo ACCEPTABLE. Dependencies and checkpoint closed. Nginx FIND-001/002/003 require operator sudo. FIND-008 inaccessible emails remain the primary operational MFA blocker. Mandatory fleet OTP remains NOT READY.", st["Body"]))
    story.append(Spacer(1, 0.6 * cm))
    story.append(p("End of report.", st["CoverSub"]))

    doc = SimpleDocTemplate(
        str(OUT),
        pagesize=A4,
        leftMargin=1.8 * cm,
        rightMargin=1.8 * cm,
        topMargin=1.6 * cm,
        bottomMargin=1.8 * cm,
        title="MediGuide STRIDE Penetration Test Report",
        author="MediGuide Capstone Security Assessment",
    )
    doc.build(story, onFirstPage=footer, onLaterPages=footer)
    return OUT


def qa(path: Path):
    """Basic PDF QA: page count, file size, non-empty."""
    from reportlab.pdfbase.pdfdoc import PDFDocument  # noqa: F401 — availability check
    data = path.read_bytes()
    assert data.startswith(b"%PDF"), "PDF header missing"
    # Count pages via /Type /Page occurrences (approx)
    pages = data.count(b"/Type /Page")
    # Subtract /Pages parent objects heuristically: prefer explicit Page objects
    page_objs = data.count(b"/Type /Page\n") + data.count(b"/Type /Page/")
    size_kb = len(data) / 1024
    report = {
        "path": str(path),
        "size_kb": round(size_kb, 1),
        "starts_with_pdf": True,
        "page_marker_count": pages,
        "approx_ok": size_kb > 20 and pages >= 8,
    }
    return report


if __name__ == "__main__":
    out = build()
    info = qa(out)
    print(f"PDF_OUT={out}")
    print(f"PDF_SIZE_KB={info['size_kb']}")
    print(f"PDF_PAGE_MARKERS={info['page_marker_count']}")
    print(f"PDF_QA_OK={info['approx_ok']}")

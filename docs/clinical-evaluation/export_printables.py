"""Convert Stage I markdown documents to printable HTML and optional DOCX."""

from __future__ import annotations

import html
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent

DOCUMENTS = [
    ("README.md", "00-package-index.html", "MediGuide Clinical Evaluation Package Index"),
    ("A-clinical-evaluation-guide.md", "A-clinical-evaluation-guide.html", "Document A — Clinical Evaluation Guide"),
    ("B-ai-conversation-evaluation-matrix.md", "B-ai-conversation-evaluation-matrix.html", "Document B — AI Conversation Evaluation Matrix"),
    ("C-clinical-safety-review-matrix.md", "C-clinical-safety-review-matrix.html", "Document C — Clinical Safety Review Matrix"),
    ("D-doctor-recommendation-evaluation.md", "D-doctor-recommendation-evaluation.html", "Document D — Doctor Recommendation Evaluation"),
    ("E-reviewer-assessment-form.md", "E-reviewer-assessment-form.html", "Document E — Reviewer Assessment Form"),
    ("P3-clinical-relevance-review.md", "P3-clinical-relevance-review.html", "Stage P.3 — Clinical Relevance Review"),
    ("P3-relevance-worksheet.md", "P3-relevance-worksheet.html", "Stage P.3 — Relevance Worksheet"),
    ("P3-review-decision-form.md", "P3-review-decision-form.html", "Stage P.3 — Review Decision Form"),
]


def inline(text: str) -> str:
    text = html.escape(text)
    text = re.sub(r"`([^`]+)`", r"<code>\1</code>", text)
    text = re.sub(r"\*\*([^*]+)\*\*", r"<strong>\1</strong>", text)
    text = re.sub(r"(?<!\*)\*([^*]+)\*(?!\*)", r"<em>\1</em>", text)
    text = text.replace("[ ]", '<span class="checkbox">☐</span>')
    return text


def is_table_separator(line: str) -> bool:
    stripped = line.strip().strip("|")
    if not stripped:
        return False
    parts = [p.strip() for p in stripped.split("|")]
    return all(re.fullmatch(r":?-{3,}:?", p or "") for p in parts)


def split_row(line: str) -> list[str]:
    line = line.strip()
    if line.startswith("|"):
        line = line[1:]
    if line.endswith("|"):
        line = line[:-1]
    return [c.strip() for c in line.split("|")]


def convert(markdown: str) -> str:
    lines = markdown.replace("\r\n", "\n").split("\n")
    out: list[str] = []
    i = 0
    in_ul = False
    in_ol = False

    def close_lists() -> None:
        nonlocal in_ul, in_ol
        if in_ul:
            out.append("</ul>")
            in_ul = False
        if in_ol:
            out.append("</ol>")
            in_ol = False

    while i < len(lines):
        line = lines[i]
        stripped = line.strip()

        if not stripped:
            close_lists()
            i += 1
            continue

        if stripped.startswith("```"):
            close_lists()
            fence = []
            i += 1
            while i < len(lines) and not lines[i].strip().startswith("```"):
                fence.append(html.escape(lines[i]))
                i += 1
            i += 1
            out.append("<pre><code>" + "\n".join(fence) + "</code></pre>")
            continue

        if re.fullmatch(r"-{3,}", stripped):
            close_lists()
            out.append("<hr>")
            i += 1
            continue

        heading = re.match(r"^(#{1,6})\s+(.*)$", stripped)
        if heading:
            close_lists()
            level = len(heading.group(1))
            out.append(f"<h{level}>{inline(heading.group(2))}</h{level}>")
            i += 1
            continue

        if stripped.startswith("|") and i + 1 < len(lines) and is_table_separator(lines[i + 1]):
            close_lists()
            headers = split_row(stripped)
            i += 2
            rows: list[list[str]] = []
            while i < len(lines) and lines[i].strip().startswith("|"):
                rows.append(split_row(lines[i]))
                i += 1
            table = ["<table>", "<thead><tr>"]
            table.extend(f"<th>{inline(h)}</th>" for h in headers)
            table.append("</tr></thead><tbody>")
            for row in rows:
                table.append("<tr>")
                padded = row + [""] * (len(headers) - len(row))
                table.extend(f"<td>{inline(cell)}</td>" for cell in padded[: len(headers)])
                table.append("</tr>")
            table.append("</tbody></table>")
            out.append("".join(table))
            continue

        ul = re.match(r"^[-*]\s+(.*)$", stripped)
        if ul:
            if in_ol:
                out.append("</ol>")
                in_ol = False
            if not in_ul:
                out.append("<ul>")
                in_ul = True
            out.append(f"<li>{inline(ul.group(1))}</li>")
            i += 1
            continue

        ol = re.match(r"^(\d+)\.\s+(.*)$", stripped)
        if ol:
            if in_ul:
                out.append("</ul>")
                in_ul = False
            if not in_ol:
                out.append("<ol>")
                in_ol = True
            out.append(f"<li>{inline(ol.group(2))}</li>")
            i += 1
            continue

        close_lists()
        comment_labels = (
            "Reviewer comments:",
            "Reviewer assessment:",
            "Recommended corrections:",
            "Strengths observed:",
            "Required corrections:",
            "Recommended improvements:",
            "Items not evaluated and why:",
            "Conditions:",
        )
        if stripped in comment_labels:
            out.append(f"<p>{inline(stripped)}</p>")
            out.append('<div class="comment-box"></div>')
            i += 1
            continue
        out.append(f"<p>{inline(stripped)}</p>")
        i += 1

    close_lists()
    return "\n".join(out)


def banner_for(source_name: str) -> str:
    if source_name.startswith("P3-"):
        return "Stage P.3 — 10 November 2026 — Not clinically approved"
    return "Stage I — 4 November 2026 — Not clinically approved"


def wrap_html(title: str, body: str, source_name: str) -> str:
    banner = html.escape(banner_for(source_name))
    return f"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{html.escape(title)}</title>
<link rel="stylesheet" href="print.css">
</head>
<body>
<p class="doc-banner"><strong>MediGuide Clinical Evaluation Package</strong> — {banner}</p>
<p class="no-print meta">Print this file on A4. Leave reviewer fields blank unless you are the reviewer completing the form.</p>
{body}
</body>
</html>
"""


def write_html() -> list[Path]:
    written: list[Path] = []
    for source_name, html_name, title in DOCUMENTS:
        source = ROOT / source_name
        target = ROOT / html_name
        body = convert(source.read_text(encoding="utf-8"))
        target.write_text(wrap_html(title, body, source_name), encoding="utf-8")
        written.append(target)
    return written


def add_runs(paragraph, text: str) -> None:
    remaining = text
    pattern = re.compile(r"(\*\*[^*]+\*\*|`[^`]+`|\*[^*]+\*)")
    while remaining:
        match = pattern.search(remaining)
        if not match:
            if remaining:
                paragraph.add_run(remaining)
            return
        if match.start():
            paragraph.add_run(remaining[: match.start()])
        token = match.group(0)
        if token.startswith("**"):
            run = paragraph.add_run(token[2:-2])
            run.bold = True
        elif token.startswith("`"):
            run = paragraph.add_run(token[1:-1])
            run.italic = True
        else:
            run = paragraph.add_run(token[1:-1])
            run.italic = True
        remaining = remaining[match.end() :]


def write_docx() -> list[Path]:
    try:
        from docx import Document
        from docx.enum.text import WD_ALIGN_PARAGRAPH
        from docx.oxml import OxmlElement
        from docx.oxml.ns import qn
        from docx.shared import Cm, Pt
    except ImportError:
        return []

    written: list[Path] = []
    for source_name, html_name, title in DOCUMENTS:
        if source_name == "README.md":
            continue
        source = ROOT / source_name
        target = ROOT / Path(html_name).with_suffix(".docx")
        markdown = source.read_text(encoding="utf-8")
        document = Document()
        section = document.sections[0]
        section.page_width = Cm(21.0)
        section.page_height = Cm(29.7)
        section.left_margin = Cm(1.8)
        section.right_margin = Cm(1.8)
        section.top_margin = Cm(1.8)
        section.bottom_margin = Cm(2.0)
        header = section.header.paragraphs[0]
        header.text = "MediGuide Clinical Evaluation Package — " + banner_for(source_name)
        header.runs[0].font.size = Pt(9)
        footer = section.footer.paragraphs[0]
        footer.alignment = WD_ALIGN_PARAGRAPH.CENTER
        run = footer.add_run(title + "  ·  Page ")
        run.font.size = Pt(9)
        begin = OxmlElement("w:fldChar")
        begin.set(qn("w:fldCharType"), "begin")
        instr = OxmlElement("w:instrText")
        instr.set(qn("xml:space"), "preserve")
        instr.text = " PAGE "
        end = OxmlElement("w:fldChar")
        end.set(qn("w:fldCharType"), "end")
        page_run = footer.add_run()
        page_run._r.append(begin)
        page_run._r.append(instr)
        page_run._r.append(end)
        page_run.font.size = Pt(9)

        lines = markdown.replace("\r\n", "\n").split("\n")
        i = 0
        while i < len(lines):
            stripped = lines[i].strip()
            if not stripped:
                i += 1
                continue
            if re.fullmatch(r"-{3,}", stripped):
                i += 1
                continue
            heading = re.match(r"^(#{1,6})\s+(.*)$", stripped)
            if heading:
                level = min(len(heading.group(1)), 4)
                paragraph = document.add_heading(heading.group(2).replace("**", ""), level=level)
                for run in paragraph.runs:
                    run.font.name = "Times New Roman"
                    run._element.rPr.rFonts.set(qn("w:eastAsia"), "Times New Roman")
                i += 1
                continue
            if stripped.startswith("|") and i + 1 < len(lines) and is_table_separator(lines[i + 1]):
                headers = split_row(stripped)
                i += 2
                rows = []
                while i < len(lines) and lines[i].strip().startswith("|"):
                    rows.append(split_row(lines[i]))
                    i += 1
                table = document.add_table(rows=1 + len(rows), cols=len(headers))
                table.style = "Table Grid"
                for col, header_text in enumerate(headers):
                    table.rows[0].cells[col].text = header_text.replace("**", "")
                for r_index, row in enumerate(rows, start=1):
                    padded = row + [""] * (len(headers) - len(row))
                    for col, cell in enumerate(padded[: len(headers)]):
                        table.rows[r_index].cells[col].text = cell.replace("**", "").replace("`", "")
                i += 0
                continue
            ul = re.match(r"^[-*]\s+(.*)$", stripped)
            if ul:
                paragraph = document.add_paragraph(style="List Bullet")
                add_runs(paragraph, ul.group(1).replace("[ ]", "☐ "))
                i += 1
                continue
            ol = re.match(r"^(\d+)\.\s+(.*)$", stripped)
            if ol:
                paragraph = document.add_paragraph(style="List Number")
                add_runs(paragraph, ol.group(2))
                i += 1
                continue
            paragraph = document.add_paragraph()
            add_runs(paragraph, stripped)
            i += 1

        document.save(target)
        written.append(target)
    return written


if __name__ == "__main__":
    html_files = write_html()
    docx_files = write_docx()
    print("HTML:")
    for path in html_files:
        print(" ", path.name)
    print("DOCX:")
    if docx_files:
        for path in docx_files:
            print(" ", path.name)
    else:
        print("  (python-docx not installed)")

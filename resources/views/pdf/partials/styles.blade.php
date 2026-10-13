<style>
    @page { margin: 28pt 28pt 46pt 28pt; }
    * { box-sizing: border-box; }
    html, body {
        background: #ffffff;
        color: #1e293b;
        color-scheme: light;
    }
    body {
        font-family: "DejaVu Sans", sans-serif;
        font-size: 9pt;
        margin: 0;
    }
    table { border-collapse: collapse; }
    .header-layout { width: 100%; margin-bottom: 14pt; }
    .header-layout td { vertical-align: top; }
    .company-column { width: 54%; padding-right: 16pt; }
    .document-column { width: 46%; text-align: right; }
    .logo { margin: 0 0 14pt 0; }
    .company-name { font-size: 12pt; font-weight: bold; color: #0f172a; margin-bottom: 3pt; }
    .company-line, .party-line { color: #334155; font-size: 8.5pt; line-height: 1.45; }
    .company-label { font-weight: bold; }
    .muted { color: #475569; font-size: 8pt; line-height: 1.35; }
    .doc-title { font-size: 16pt; font-weight: bold; letter-spacing: 0.04em; color: #0f172a; }
    .doc-number { font-size: 10pt; font-weight: bold; margin-top: 4pt; color: #0f172a; }
    .line { font-size: 9pt; color: #334155; margin-top: 3pt; }
    .party { margin-top: 14pt; text-align: left; }
    .party-label { font-size: 8pt; font-weight: bold; letter-spacing: 0.06em; text-transform: uppercase; color: #334155; margin-bottom: 3pt; }
    .party-name { font-size: 10pt; font-weight: bold; color: #0f172a; margin-bottom: 2pt; }
    .header-foot { border-top: 0.6pt solid #e2e8f0; padding-top: 8pt; font-size: 9pt; color: #0f172a; }
    .po-meta { margin: 0 0 12pt 0; }
    .meta-gap { margin-left: 12pt; }
    .party-grid { width: 100%; margin: 0 0 8pt 0; }
    .party-grid td { width: 33.33%; vertical-align: top; border: 0.6pt solid #e2e8f0; background: #ffffff; padding: 8pt; }
    .totals-layout { width: 100%; margin: 0 0 10pt 0; }
    .totals-layout > tbody > tr > td, .totals-layout > tr > td { vertical-align: top; }
    .words { width: 58%; padding-right: 12pt; }
    .totals-layout .data { width: 100%; }
    .sign-right { width: 170pt; margin-left: 90pt; }
    .badge {
        display: inline-block;
        margin-top: 4pt;
        border: 0.6pt solid #cbd5e1;
        background: #f8fafc;
        color: #334155;
        font-size: 7.5pt;
        font-weight: bold;
        letter-spacing: 0.04em;
        padding: 2pt 6pt;
    }
    .badge-approved, .badge-paid { border-color: #86efac; background: #f0fdf4; color: #166534; }
    .badge-rejected { border-color: #fecaca; background: #fef2f2; color: #991b1b; }
    .badge-submitted, .badge-recorded, .badge-partially_paid { border-color: #cbd5e1; background: #f8fafc; color: #1e293b; }
    .badge-draft { color: #475569; }
    .block { border: 0.6pt solid #e2e8f0; margin: 0 0 10pt 0; page-break-inside: avoid; }
    .block-title {
        background: #f4f6f8;
        border-bottom: 0.6pt solid #e2e8f0;
        color: #334155;
        font-size: 8pt;
        font-weight: bold;
        letter-spacing: 0.04em;
        padding: 4pt 6pt;
        text-transform: uppercase;
    }
    .fields { width: 100%; }
    .fields td { width: 33.33%; background: #ffffff; padding: 4pt 6pt; vertical-align: top; border-top: 0.4pt solid #f1f5f9; }
    .k { display: block; color: #64748b; font-size: 7.5pt; text-transform: uppercase; }
    .v { display: block; color: #0f172a; font-size: 9pt; }
    table.data { width: 100%; margin: 0 0 10pt 0; }
    table.data th {
        background: #f4f6f8;
        border: 0.6pt solid #e2e8f0;
        color: #334155;
        font-size: 7.5pt;
        font-weight: bold;
        letter-spacing: 0.03em;
        padding: 4pt 5pt;
        text-align: left;
        text-transform: uppercase;
    }
    table.data td { background: #ffffff; border: 0.6pt solid #e2e8f0; padding: 3.5pt 5pt; vertical-align: top; }
    table.data.dense th, table.data.dense td { font-size: 7pt; padding: 2.5pt 3pt; }
    table.data thead { display: table-header-group; }
    table.data tr { page-break-inside: avoid; }
    table.data tfoot td { background: #fafafa; font-weight: bold; }
    .num { text-align: right; }
    .center { text-align: center; }
    .wrap { word-wrap: break-word; }
    .prose { font-size: 9pt; margin: 0; white-space: pre-line; }
    .alert {
        border: 0.6pt solid #cbd5e1;
        background: #f8fafc;
        font-size: 8pt;
        font-weight: bold;
        letter-spacing: 0.04em;
        margin: 0 0 10pt 0;
        padding: 5pt 6pt;
    }
    .sign { width: 100%; margin-top: 8pt; page-break-inside: avoid; }
    .sign td { width: 50%; vertical-align: bottom; }
    .sign-line { border-top: 0.6pt solid #94a3b8; color: #475569; font-size: 8pt; margin-top: 28pt; padding-top: 3pt; width: 160pt; }
    .footer {
        position: fixed;
        left: 0;
        right: 0;
        bottom: -30pt;
        border-top: 0.6pt solid #e2e8f0;
        color: #64748b;
        font-size: 7.5pt;
        padding-top: 4pt;
    }
</style>

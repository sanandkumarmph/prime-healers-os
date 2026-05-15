@page {
    size: A4 portrait;
    margin: 14mm;
}

* {
    box-sizing: border-box;
}

html,
body {
    width: 100%;
    max-width: 100%;
    margin: 0;
    padding: 0;
    background: #ffffff;
}

body {
    color: #24384f;
    font-family: 'Inter', 'Segoe UI', Roboto, Arial, sans-serif;
    font-size: 10px;
    line-height: 1.4;
    font-variant-numeric: tabular-nums;
    -webkit-font-smoothing: antialiased;
}

.invoice-page,
.invoice-document {
    width: 100%;
    max-width: 100%;
    margin: 0;
    padding: 0;
    overflow: hidden;
    box-sizing: border-box;
    page-break-inside: auto;
}

.header-table,
.meta-table,
.party-table,
.items-table,
.summary-table,
.payment-details-table,
.payments-table,
.totals-table,
.footer-table {
    width: 100%;
    max-width: 100%;
    border-collapse: collapse;
}

.header-table {
    table-layout: fixed;
    margin-bottom: 8px;
    border-bottom: 1px solid #d7e1ec;
}

.header-table td {
    vertical-align: top;
    padding-bottom: 6px;
}

.header-logo-cell {
    width: 30mm;
    padding-right: 6px;
}

.header-logo-box {
    width: 27mm;
    height: 14mm;
    padding: 0.4mm;
    border: 1px solid #d7e1ec;
    background: #ffffff;
    overflow: hidden;
    text-align: center;
    vertical-align: middle;
    line-height: 0;
}

.header-logo-box img,
.invoice-logo {
    display: block;
    width: auto !important;
    height: auto !important;
    max-width: 26mm;
    max-height: 13mm;
    margin: 0 auto;
}

.invoice-logo {
    max-width: 95px !important;
    max-height: 45px !important;
}

.header-logo-fallback {
    padding: 4px 4px 0;
    color: #24384f;
    font-size: 8.8px;
    font-weight: 700;
    line-height: 1.25;
}

.header-company-cell {
    padding-right: 8px;
}

.header-company-name {
    margin: 0 0 3px;
    color: #12263F;
    font-size: 13px;
    font-weight: 700;
    line-height: 1.2;
    font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
}

.header-company-line {
    margin: 0;
    color: #24384f;
    font-size: 8.8px;
    line-height: 1.35;
}

.header-title-cell {
    width: 42mm;
    text-align: right;
    padding-top: 0;
    padding-right: 0;
}

.header-invoice-title {
    margin: 0 0 5px;
    color: #12263F;
    font-size: 17px;
    font-weight: 700;
    line-height: 1;
    text-transform: uppercase;
    font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
}

.status-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    border: 1px solid transparent;
    font-size: 8.8px;
    font-weight: 800;
    text-transform: uppercase;
}

.status-paid {
    color: #0E9F4B;
    background: #EAF8F0;
    border-color: #0E9F4B;
}

.status-partial {
    color: #B7791F;
    background: #FFF7E8;
    border-color: #B7791F;
}

.status-unpaid {
    color: #B30D23;
    background: #FDECEF;
    border-color: #B30D23;
}

.status-draft {
    color: #5B6E84;
    background: #EEF3F8;
    border-color: #C2D0DE;
}

.meta-table {
    table-layout: fixed;
    margin-bottom: 6px;
    border: 1px solid #d7e1ec;
}

.meta-table td {
    width: 33.33%;
    padding: 5px 6px;
    background: #F8FBFE;
    border-right: 1px solid #d7e1ec;
    border-bottom: 1px solid #d7e1ec;
    vertical-align: top;
}

.meta-table tr:last-child td {
    border-bottom: 0;
}

.meta-table td:nth-child(3n) {
    border-right: 0;
}

.meta-label {
    display: block;
    margin-bottom: 2px;
    color: #5B6E84;
    font-size: 7.8px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.meta-value {
    display: block;
    color: #12263F;
    font-size: 9.5px;
    font-weight: 700;
    word-break: break-word;
}

.party-table {
    table-layout: fixed;
    margin-bottom: 6px;
    border: 1px solid #d7e1ec;
}

.party-table td {
    width: 50%;
    padding: 6px 8px;
    vertical-align: top;
}

.party-table td:first-child {
    border-right: 1px solid #d7e1ec;
}

.section-title {
    margin: 0 0 4px;
    color: #5B6E84;
    font-size: 9px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
}

.party-line {
    margin: 0 0 2px;
    color: #24384f;
    font-size: 9px;
    line-height: 1.35;
    word-break: break-word;
}

.subject-row {
    margin-bottom: 6px;
    padding: 5px 6px;
    border: 1px solid #d7e1ec;
    background: #F8FBFE;
    color: #24384f;
    font-size: 9px;
}

.subject-row strong {
    margin-right: 6px;
}

.items-table {
    table-layout: fixed;
    margin-bottom: 6px;
}

.items-table thead {
    display: table-header-group;
}

.items-table th,
.items-table td {
    border: 1px solid #d7e1ec;
    padding: 5px 4px;
    vertical-align: top;
}

.items-table th {
    background: #F0F6FB;
    color: #12263F;
    font-size: 8.2px;
    font-weight: 800;
    text-align: left;
    font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
}

.items-table td {
    color: #24384f;
    font-size: 8.8px;
}

.num {
    text-align: right;
    white-space: nowrap;
}

.item-title {
    display: block;
    color: #12263F;
    font-weight: 700;
    line-height: 1.35;
}

.item-subtext {
    display: block;
    margin-top: 2px;
    color: #5B6E84;
    font-size: 8px;
    line-height: 1.35;
}

.summary-table,
.payment-card {
    page-break-inside: avoid;
}

.summary-table {
    table-layout: fixed;
}

.summary-table td {
    vertical-align: top;
}

.summary-notes-cell {
    width: 56%;
    padding-right: 6px;
}

.summary-totals-cell {
    width: 44%;
    padding-left: 6px;
}

.summary-notes-wrap,
.summary-totals-wrap {
    width: 100%;
}

.summary-totals-wrap {
    display: block;
    width: 78mm;
    max-width: 100%;
    margin-left: auto;
    text-align: left;
}

.notes-box,
.payment-card,
.signature-box {
    border: 1px solid #d7e1ec;
    padding: 6px 8px;
}

.notes-box p,
.notes-box ul,
.notes-box div {
    margin: 0 0 5px;
    color: #24384f;
    font-size: 8.8px;
    line-height: 1.35;
}

.notes-box ul {
    padding-left: 14px;
}

.notes-box li {
    margin: 0 0 4px;
}

.payment-card {
    margin-top: 6px;
}

.payment-details-table td {
    width: 50%;
    padding: 0;
    vertical-align: top;
}

.payment-details-table td:first-child {
    border-right: 1px solid #d7e1ec;
    padding-right: 6px;
}

.payment-bank-line {
    margin: 0 0 4px;
    color: #24384f;
    font-size: 8.8px;
    line-height: 1.35;
}

.payment-qr-col {
    width: 27mm;
    text-align: center;
}

.payment-qr-box {
    text-align: center;
}

.payment-qr-caption {
    margin-top: 4px;
    color: #5B6E84;
    font-size: 7.8px;
    line-height: 1.3;
}

.qr-image {
    display: block;
    width: 24mm;
    height: 24mm;
    margin: 0 auto;
}

.payments-table {
    margin-top: 6px;
    page-break-inside: auto;
}

.payments-table th,
.payments-table td {
    border: 1px solid #d7e1ec;
    padding: 5px 6px;
    vertical-align: top;
}

.payments-table th {
    background: #F0F6FB;
    color: #12263F;
    font-size: 8.2px;
    font-weight: 800;
    text-align: left;
    font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
}

.payments-table td {
    color: #24384f;
    font-size: 8.8px;
    white-space: normal;
    word-break: normal;
    overflow-wrap: break-word;
}

.payments-table th:first-child {
    width: 18%;
}

.payments-table th:nth-child(2) {
    width: 16%;
}

.payments-table th:nth-child(3) {
    width: 46%;
}

.payments-table th:last-child {
    width: 20%;
}

.totals-table td {
    border: 1px solid #d7e1ec;
    padding: 5px 6px;
    color: #24384f;
    font-size: 9px;
}

.totals-table td:last-child {
    text-align: right;
    white-space: nowrap;
}

.grand-total td {
    background: #EEF3F8;
    color: #12263F;
    font-size: 10.2px;
    font-weight: 800;
    font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
}

.balance-due td {
    background: #EAF8F0;
    color: #12263F;
    font-size: 10.2px;
    font-weight: 800;
    font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
}

.signature-box {
    min-height: 0;
    margin-top: 6px;
    text-align: right;
}

.signature-box img {
    max-width: 36mm;
    max-height: 14mm;
    display: block;
    margin-left: auto;
    margin-bottom: 4px;
}

.signature-line {
    width: 36mm;
    height: 14mm;
    border-bottom: 1px solid #c2d0de;
    margin-left: auto;
    margin-bottom: 4px;
}

.footer-table {
    margin-top: 6px;
}

.footer-table td {
    color: #5B6E84;
    font-size: 7.8px;
    vertical-align: top;
}

.footer-right {
    text-align: right;
}

body.pdf-document {
    margin: 0;
    padding: 0;
    background: #ffffff;
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 9.5px;
    line-height: 1.35;
}

.pdf-page-shell {
    width: 100%;
    max-width: 100%;
    padding: 10mm;
    box-sizing: border-box;
    background: #ffffff;
}

body.pdf-document .header-company-name,
body.pdf-document .header-invoice-title,
body.pdf-document .section-title,
body.pdf-document .items-table th,
body.pdf-document .status-badge,
body.pdf-document .grand-total td,
body.pdf-document .balance-due td {
    font-family: DejaVu Sans, Arial, sans-serif;
}

body.pdf-document .header-company-name {
    font-size: 12.5px;
}

body.pdf-document .header-invoice-title {
    font-size: 16.5px;
}

body.pdf-document .meta-table td,
body.pdf-document .party-table td,
body.pdf-document .notes-box,
body.pdf-document .payment-card,
body.pdf-document .signature-box {
    padding: 5px 6px;
}

body.pdf-document .items-table th,
body.pdf-document .items-table td,
body.pdf-document .payments-table th,
body.pdf-document .payments-table td,
body.pdf-document .totals-table td {
    padding: 4px 4px;
}

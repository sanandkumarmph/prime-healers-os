@page {
    size: A4 portrait;
    margin: 12mm;
}

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    background: #ffffff;
}

body {
    color: #24384f;
    font-family: 'Inter', 'Segoe UI', Roboto, Arial, sans-serif;
    font-size: 11px;
    line-height: 1.45;
    font-variant-numeric: tabular-nums;
    -webkit-font-smoothing: antialiased;
}

.invoice-page {
    width: 100%;
    margin: 0;
    padding: 0;
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
    border-collapse: collapse;
}

.header-table {
    margin-bottom: 10px;
    border-bottom: 1px solid #d7e1ec;
}

.header-table td {
    vertical-align: top;
    padding-bottom: 10px;
}

.header-logo-cell {
    width: 36mm;
    padding-right: 10px;
}

.header-logo-box {
    width: 32mm;
    height: 18mm;
    padding: 0.8mm;
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
    max-width: 31mm;
    max-height: 16.5mm;
    margin: 0 auto;
}

.invoice-logo {
    max-width: 120px !important;
    max-height: 60px !important;
}

.header-logo-fallback {
    padding: 8px 6px 0;
    color: #24384f;
    font-size: 10px;
    font-weight: 700;
    line-height: 1.25;
}

.header-company-cell {
    padding-right: 12px;
}

.header-company-name {
    margin: 0 0 5px;
    color: #12263F;
    font-size: 15px;
    font-weight: 700;
    line-height: 1.2;
    font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
}

.header-company-line {
    margin: 0;
    color: #24384f;
    font-size: 9.8px;
    line-height: 1.45;
}

.header-title-cell {
    width: 52mm;
    text-align: right;
    padding-top: 2px;
    padding-right: 2px;
}

.header-invoice-title {
    margin: 0 0 8px;
    color: #12263F;
    font-size: 22px;
    font-weight: 700;
    line-height: 1;
    text-transform: uppercase;
    font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
}

.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 999px;
    border: 1px solid transparent;
    font-size: 10px;
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
    margin-bottom: 8px;
    border: 1px solid #d7e1ec;
}

.meta-table td {
    width: 33.33%;
    padding: 7px 10px;
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
    margin-bottom: 3px;
    color: #5B6E84;
    font-size: 8.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.meta-value {
    display: block;
    color: #12263F;
    font-size: 10.5px;
    font-weight: 700;
    word-break: break-word;
}

.party-table {
    margin-bottom: 8px;
    border: 1px solid #d7e1ec;
}

.party-table td {
    width: 50%;
    padding: 9px 11px;
    vertical-align: top;
}

.party-table td:first-child {
    border-right: 1px solid #d7e1ec;
}

.section-title {
    margin: 0 0 5px;
    color: #5B6E84;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
}

.party-line {
    margin: 0 0 3px;
    color: #24384f;
    font-size: 10px;
    line-height: 1.45;
    word-break: break-word;
}

.subject-row {
    margin-bottom: 8px;
    padding: 7px 10px;
    border: 1px solid #d7e1ec;
    background: #F8FBFE;
    color: #24384f;
    font-size: 10px;
}

.subject-row strong {
    margin-right: 6px;
}

.items-table {
    margin-bottom: 8px;
}

.items-table thead {
    display: table-header-group;
}

.items-table th,
.items-table td {
    border: 1px solid #d7e1ec;
    padding: 7px 6px;
    vertical-align: top;
}

.items-table th {
    background: #F0F6FB;
    color: #12263F;
    font-size: 9px;
    font-weight: 800;
    text-align: left;
    font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
}

.items-table td {
    color: #24384f;
    font-size: 9.7px;
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
    font-size: 8.7px;
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
    width: 58%;
    padding-right: 10px;
}

.summary-totals-cell {
    width: 42%;
    padding-left: 10px;
}

.summary-notes-wrap,
.summary-totals-wrap {
    width: 100%;
}

.summary-totals-wrap {
    display: block;
    width: 92mm;
    max-width: 100%;
    margin-left: auto;
    text-align: left;
}

.notes-box,
.payment-card,
.signature-box {
    border: 1px solid #d7e1ec;
    padding: 9px 11px;
}

.notes-box p,
.notes-box ul,
.notes-box div {
    margin: 0 0 7px;
    color: #24384f;
    font-size: 9.6px;
    line-height: 1.45;
}

.notes-box ul {
    padding-left: 14px;
}

.notes-box li {
    margin: 0 0 4px;
}

.payment-card {
    margin-top: 8px;
}

.payment-details-table td {
    width: 50%;
    padding: 0;
    vertical-align: top;
}

.payment-details-table td:first-child {
    border-right: 1px solid #d7e1ec;
    padding-right: 10px;
}

.payment-bank-line {
    margin: 0 0 4px;
    color: #24384f;
    font-size: 9.6px;
    line-height: 1.45;
}

.payment-qr-col {
    width: 32mm;
    text-align: center;
}

.payment-qr-box {
    text-align: center;
}

.payment-qr-caption {
    margin-top: 6px;
    color: #5B6E84;
    font-size: 8.8px;
    line-height: 1.3;
}

.qr-image {
    display: block;
    width: 29mm;
    height: 29mm;
    margin: 0 auto;
}

.payments-table {
    margin-top: 8px;
    page-break-inside: auto;
}

.payments-table th,
.payments-table td {
    border: 1px solid #d7e1ec;
    padding: 7px 8px;
    vertical-align: top;
}

.payments-table th {
    background: #F0F6FB;
    color: #12263F;
    font-size: 9px;
    font-weight: 800;
    text-align: left;
    font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
}

.payments-table td {
    color: #24384f;
    font-size: 9.6px;
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
    padding: 7px 8px;
    color: #24384f;
    font-size: 10px;
}

.totals-table td:last-child {
    text-align: right;
    white-space: nowrap;
}

.grand-total td {
    background: #EEF3F8;
    color: #12263F;
    font-size: 11.5px;
    font-weight: 800;
    font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
}

.balance-due td {
    background: #EAF8F0;
    color: #12263F;
    font-size: 11.5px;
    font-weight: 800;
    font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
}

.signature-box {
    min-height: 0;
    margin-top: 8px;
    text-align: right;
}

.signature-box img {
    max-width: 42mm;
    max-height: 18mm;
    display: block;
    margin-left: auto;
    margin-bottom: 5px;
}

.signature-line {
    width: 42mm;
    height: 18mm;
    border-bottom: 1px solid #c2d0de;
    margin-left: auto;
    margin-bottom: 5px;
}

.footer-table {
    margin-top: 10px;
}

.footer-table td {
    color: #5B6E84;
    font-size: 8.8px;
    vertical-align: top;
}

.footer-right {
    text-align: right;
}

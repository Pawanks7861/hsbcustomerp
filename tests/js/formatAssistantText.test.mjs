import assert from 'node:assert/strict';
import test from 'node:test';
import {
    ALLOWED_TAGS,
    applyAssistantMarkup,
    escapeHtml,
    formatAssistantText,
    safeInternalHref,
    sanitizeAssistantHtml,
} from '../../resources/js/chatbot/formatAssistantText.js';

test('AI-generated HTML is escaped the same as user or stored text', () => {
    const html = formatAssistantText('<p onclick="alert(1)">Payable is <b>high</b></p><iframe src="javascript:alert(1)"></iframe>');
    assert.equal(
        html,
        '&lt;p onclick=&quot;alert(1)&quot;&gt;Payable is &lt;b&gt;high&lt;/b&gt;&lt;/p&gt;&lt;iframe src=&quot;javascript:alert(1)&quot;&gt;&lt;/iframe&gt;',
    );
    assert.doesNotMatch(html, /<[a-z][^>]*\son\w+\s*=/i);
    assert.doesNotMatch(html, /<iframe[\s>]/i);
    assert.doesNotMatch(html, /<b>/i);
});

test('ordinary text is escaped and otherwise unchanged', () => {
    const html = formatAssistantText('Outstanding payable is 1,000.00');
    assert.equal(html, 'Outstanding payable is 1,000.00');
});

test('legitimate bold and newlines remain functional', () => {
    const html = formatAssistantText('Open **Projects** from the sidebar.\nThen save.');
    assert.equal(html, 'Open <strong>Projects</strong> from the sidebar.<br>Then save.');
});

test('script payloads are escaped so they cannot execute', () => {
    const html = formatAssistantText('<script>alert(1)</script>Evil Co');
    assert.equal(html, '&lt;script&gt;alert(1)&lt;/script&gt;Evil Co');
    assert.doesNotMatch(html, /<script/i);
});

test('event-handler attributes in stored names are escaped', () => {
    const html = formatAssistantText('<img src=x onerror=alert(1)>XSS Co');
    assert.equal(html, '&lt;img src=x onerror=alert(1)&gt;XSS Co');
    assert.doesNotMatch(html, /<img[\s>]/i);
    assert.doesNotMatch(html, /<[a-z][^>]*\sonerror\s*=/i);
});

test('javascript URLs in message text are escaped, not turned into links', () => {
    const html = formatAssistantText('Click **<a href="javascript:alert(1)">here</a>**');
    assert.equal(
        html,
        'Click <strong>&lt;a href=&quot;javascript:alert(1)&quot;&gt;here&lt;/a&gt;</strong>',
    );
    assert.doesNotMatch(html, /href="javascript:/i);
});

test('malicious markup inside bold is escaped before wrapping', () => {
    const html = formatAssistantText('Vendor **<svg onload=alert(1)>** balance');
    assert.equal(html, 'Vendor <strong>&lt;svg onload=alert(1)&gt;</strong> balance');
    assert.doesNotMatch(html, /<svg[\s>]/i);
    assert.doesNotMatch(html, /<[a-z][^>]*\sonload\s*=/i);
});

test('iframe and script tags from a simulated stored reply stay text', () => {
    const storedHistory = 'See <iframe src="javascript:alert(1)"></iframe> and <script>document.cookie</script>';
    const html = formatAssistantText(storedHistory);
    assert.match(html, /&lt;iframe/);
    assert.match(html, /&lt;script/);
    assert.doesNotMatch(html, /<iframe/i);
    assert.doesNotMatch(html, /<script/i);
});

test('sanitizeAssistantHtml uses the allowlist sanitizer when provided', () => {
    let seenConfig = null;
    const purify = {
        sanitize(html, config) {
            seenConfig = config;
            return html.replace(/<em>/g, '');
        },
    };

    const html = sanitizeAssistantHtml('<strong>OK</strong><em>x</em>', purify);
    assert.deepEqual(seenConfig.ALLOWED_TAGS, ALLOWED_TAGS);
    assert.deepEqual(seenConfig.ALLOWED_ATTR, []);
    assert.equal(seenConfig.ALLOW_UNKNOWN_PROTOCOLS, false);
    assert.equal(html, '<strong>OK</strong>x</em>');
});

test('formatAssistantText passes markup through the sanitizer hook', () => {
    const purify = {
        sanitize(html) {
            assert.match(html, /<strong>Safe<\/strong>/);
            assert.doesNotMatch(html, /<script/i);
            return html;
        },
    };

    const html = formatAssistantText('**Safe**<script>alert(1)</script>', purify);
    assert.equal(html, '<strong>Safe</strong>&lt;script&gt;alert(1)&lt;/script&gt;');
});

test('safeInternalHref allows in-app paths and blocks javascript and off-site URLs', () => {
    assert.equal(safeInternalHref('/vendors/12/ledger'), '/vendors/12/ledger');
    assert.equal(safeInternalHref('/reports/stock-balance?project_id=1'), '/reports/stock-balance?project_id=1');
    assert.equal(safeInternalHref('javascript:alert(1)'), null);
    assert.equal(safeInternalHref('JAVASCRIPT:alert(1)'), null);
    assert.equal(safeInternalHref(' data:text/html,alert(1)'), null);
    assert.equal(safeInternalHref('https://evil.example'), null);
    assert.equal(safeInternalHref('//evil.example/path'), null);
    assert.equal(safeInternalHref('vbscript:msgbox(1)'), null);
    assert.equal(safeInternalHref('\\windows\\path'), null);
});

test('escapeHtml and applyAssistantMarkup stay ordered so markup is not user-controlled', () => {
    const escaped = escapeHtml('**<script>**');
    assert.equal(escaped, '**&lt;script&gt;**');
    assert.equal(applyAssistantMarkup(escaped), '<strong>&lt;script&gt;</strong>');
});

test('fallback rendering before DOMPurify loads still cannot inject markup', () => {
    const html = applyAssistantMarkup(escapeHtml('<img src=x onerror=alert(1)> **Total** <script>x</script>'));
    assert.doesNotMatch(html, /<img|<script/i);
    assert.match(html, /<strong>Total<\/strong>/);
});

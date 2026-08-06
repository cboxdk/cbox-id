// Headless a11y check: runs axe-core over a page's HTML inside jsdom.
// Used by tests/Feature/AccessibilityTest.php so a11y regressions fail the
// normal test suite — no browser or driver required.
//
// color-contrast is disabled: jsdom has no layout engine, so axe cannot compute
// it. Contrast is verified separately (design tokens + a full-browser axe run).
const { JSDOM } = require('jsdom');
const axe = require('axe-core');
const fs = require('fs');

(async () => {
  const html = fs.readFileSync(process.argv[2], 'utf8');
  const dom = new JSDOM(html, { runScripts: 'dangerously', pretendToBeVisual: true });
  const { window } = dom;
  window.eval(axe.source);

  const results = await window.axe.run(window.document.body, {
    runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
    rules: { 'color-contrast': { enabled: false } },
  });

  const violations = results.violations.map((v) => ({
    id: v.id,
    impact: v.impact,
    nodes: v.nodes.length,
    help: v.help,
    target: v.nodes[0]?.target?.join(' '),
  }));

  process.stdout.write(JSON.stringify(violations));
})().catch((e) => {
  process.stderr.write(String(e && e.message ? e.message : e));
  process.exit(2);
});

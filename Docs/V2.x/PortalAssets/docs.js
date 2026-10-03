(() => {
  'use strict';

  const one = (selector, root = document) => root.querySelector(selector);
  const all = (selector, root = document) => [...root.querySelectorAll(selector)];
  const body = document.body;
  const rootUrl = new URL(body.dataset.siteRoot || '/', location.href);
  const resolve = path => new URL(path.replace(/^\//, ''), rootUrl).href;
  const activeVersion = body.dataset.version || 'v2.x';
  const landingSearch = body.classList.contains('portal-page');
  const searchDisplayText = value => landingSearch
    ? value.replace(/\s*\bv2(?:\.(?:x|\d+(?:\.\d+)*))?\b/gi, '').replace(/\s{2,}/g, ' ').trim()
    : value;

  // A visitor's explicit choice takes priority over the operating system.
  const themes = ['system', 'light', 'dark'];
  const themeButton = one('.theme-button');
  function setTheme(value) {
    const theme = themes.includes(value) ? value : 'system';
    document.documentElement.dataset.theme = theme;
    if (themeButton) {
      themeButton.setAttribute('aria-label', `Appearance: ${theme}. Activate to change`);
      themeButton.title = `Appearance: ${theme}`;
      one('span', themeButton).textContent = {system: '◐', light: '☼', dark: '☾'}[theme];
    }
    try { localStorage.setItem('squehub-docs-theme', theme); } catch (_) { /* Private browsing. */ }
  }
  let savedTheme = 'system';
  try { savedTheme = localStorage.getItem('squehub-docs-theme') || 'system'; } catch (_) { /* Private browsing. */ }
  setTheme(savedTheme);
  themeButton?.addEventListener('click', () => {
    setTheme(themes[(themes.indexOf(document.documentElement.dataset.theme) + 1) % themes.length]);
  });

  const versionButton = one('.version-button');
  const versionMenu = one('.version-menu');
  function closeVersion() {
    if (!versionButton || !versionMenu) return;
    versionButton.setAttribute('aria-expanded', 'false');
    versionMenu.hidden = true;
  }
  versionButton?.addEventListener('click', event => {
    event.stopPropagation();
    const open = versionMenu.hidden;
    versionMenu.hidden = !open;
    versionButton.setAttribute('aria-expanded', String(open));
    if (open) one('a', versionMenu)?.focus();
  });
  versionMenu?.addEventListener('keydown', event => {
    const options = all('a[role="menuitem"]', versionMenu);
    const current = options.indexOf(document.activeElement);
    if (event.key === 'Escape') {
      event.preventDefault(); closeVersion(); versionButton.focus();
    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      const step = event.key === 'ArrowDown' ? 1 : -1;
      options[(current + step + options.length) % options.length]?.focus();
    }
  });
  document.addEventListener('click', event => {
    if (!event.target.closest('.version-wrap')) closeVersion();
  });

  const menuButton = one('[data-menu-open]');
  const drawer = one('.drawer-backdrop');
  const mobileNav = one('.mobile-site-nav');
  function closeMenu() {
    body.classList.remove('menu-open');
    if (drawer) drawer.hidden = true;
    if (mobileNav) mobileNav.hidden = true;
    menuButton?.setAttribute('aria-expanded', 'false');
  }
  menuButton?.addEventListener('click', () => {
    const open = menuButton.getAttribute('aria-expanded') !== 'true';
    closeMenu();
    if (!open) return;
    menuButton.setAttribute('aria-expanded', 'true');
    if (one('.docs-sidebar')) {
      body.classList.add('menu-open');
      if (drawer) drawer.hidden = false;
    } else if (mobileNav) mobileNav.hidden = false;
  });
  drawer?.addEventListener('click', closeMenu);
  all('.docs-sidebar a, .mobile-site-nav a').forEach(link => link.addEventListener('click', closeMenu));
  window.addEventListener('resize', () => { if (innerWidth > 850) closeMenu(); });
  all('.section-toggle').forEach(button => button.addEventListener('click', () => {
    const links = button.nextElementSibling;
    const expanded = button.getAttribute('aria-expanded') === 'true';
    button.setAttribute('aria-expanded', String(!expanded));
    links.hidden = expanded;
  }));

  const overlay = one('.search-overlay');
  const input = one('#search-input');
  const results = one('#search-results');
  let matches = [];
  let selected = 0;
  let previousFocus = null;
  const index = (window.SQUEHUB_SEARCH_INDEX || []).filter(page => page.version === activeVersion);
  // Match the build-time index's word, dot, and hyphen token boundaries.
  const searchWords = value => [...new Set(value.toLocaleLowerCase()
    .match(/[\p{L}\p{N}_.-]+/gu) || [])];

  function highlight(element, value, query) {
    const lower = value.toLocaleLowerCase();
    const needle = searchWords(query).find(word => lower.includes(word)) || '';
    const at = needle ? lower.indexOf(needle) : -1;
    if (at < 0) { element.textContent = value; return; }
    element.append(document.createTextNode(value.slice(0, at)));
    const mark = document.createElement('mark');
    mark.textContent = value.slice(at, at + needle.length);
    element.append(mark, document.createTextNode(value.slice(at + needle.length)));
  }
  function score(page, words) {
    const title = page.title.toLocaleLowerCase();
    const headings = (page.headings || '').toLocaleLowerCase();
    const content = (page.content || '').toLocaleLowerCase();
    let sum = 0;
    for (const word of words) {
      if (title === word) sum += 24;
      else if (title.includes(word)) sum += 14;
      else if (headings.includes(word)) sum += 8;
      else if (page.category.toLocaleLowerCase().includes(word)) sum += 5;
      else if (content.includes(word)) sum += 1;
      else return -1;
    }
    return sum;
  }
  function select(indexValue) {
    const links = all('.search-result', results);
    if (!links.length) return;
    selected = (indexValue + links.length) % links.length;
    links.forEach((link, position) => link.setAttribute('aria-selected', String(position === selected)));
    input?.setAttribute('aria-activedescendant', links[selected].id);
    links[selected].scrollIntoView({block: 'nearest'});
  }
  function renderResults() {
    if (!results || !input) return;
    const query = input.value.trim().slice(0, 120);
    const words = searchWords(query);
    matches = words.length
      ? index.map(page => ({page, rank: score(page, words)}))
        .filter(item => item.rank > 0)
        .sort((a, b) => b.rank - a.rank || a.page.title.localeCompare(b.page.title))
        .slice(0, 12).map(item => item.page)
      : index.filter(page => ['installation', 'routing', 'views', 'database',
        'upgrade-from-v1', 'first-application'].includes(page.url.split('/').pop())).slice(0, 6);
    selected = 0;
    results.replaceChildren();
    input.removeAttribute('aria-activedescendant');
    if (!matches.length) {
      const empty = document.createElement('p');
      empty.className = 'search-empty';
      empty.textContent = 'No results in this documentation version. Try a shorter or different term.';
      results.append(empty);
      return;
    }
    matches.forEach((page, position) => {
      const link = document.createElement('a');
      link.className = 'search-result';
      link.href = resolve(page.url);
      link.id = `search-result-${position}`;
      link.setAttribute('role', 'option');
      link.setAttribute('aria-selected', String(position === 0));
      const category = document.createElement('span');
      category.className = 'result-category';
      category.textContent = landingSearch
        ? `Documentation · ${searchDisplayText(page.category)}`
        : `${page.version} · ${page.category}`;
      const title = document.createElement('strong');
      highlight(title, searchDisplayText(page.title), query);
      const summary = document.createElement('small');
      const section = (page.sections || []).find(heading =>
        words.some(word => heading.toLocaleLowerCase().includes(word)));
      highlight(summary, searchDisplayText(section ? `In ${section} · ${page.summary}` : page.summary), query);
      link.append(category, title, summary);
      link.addEventListener('mouseenter', () => select(position));
      results.append(link);
    });
    input.setAttribute('aria-activedescendant', 'search-result-0');
  }
  function openSearch() {
    if (!overlay || !input) return;
    closeMenu(); closeVersion();
    previousFocus = document.activeElement;
    overlay.hidden = false;
    body.classList.add('search-open');
    input.setAttribute('aria-expanded', 'true');
    input.value = '';
    renderResults();
    input.focus();
  }
  function closeSearch() {
    if (!overlay || overlay.hidden) return;
    overlay.hidden = true;
    body.classList.remove('search-open');
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
    previousFocus?.focus();
  }
  all('[data-search-open]').forEach(button => button.addEventListener('click', openSearch));
  all('[data-search-close]').forEach(button => button.addEventListener('click', closeSearch));
  input?.addEventListener('input', renderResults);
  input?.addEventListener('keydown', event => {
    if (event.key === 'ArrowDown') { event.preventDefault(); select(selected + 1); }
    if (event.key === 'ArrowUp') { event.preventDefault(); select(selected - 1); }
    if (event.key === 'Enter' && matches[selected]) { location.href = resolve(matches[selected].url); }
  });
  document.addEventListener('keydown', event => {
    const target = document.activeElement;
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault(); overlay?.hidden ? openSearch() : closeSearch();
    } else if (event.key === '/' && overlay?.hidden && !['INPUT', 'TEXTAREA'].includes(target.tagName)) {
      event.preventDefault(); openSearch();
    } else if (event.key === 'Escape') {
      closeSearch(); closeMenu(); closeVersion();
    } else if (event.key === 'Tab' && overlay && !overlay.hidden) {
      const controls = all('input, button, a', overlay).filter(element => element.getClientRects().length);
      if (controls.length && event.shiftKey && target === controls[0]) {
        event.preventDefault(); controls.at(-1).focus();
      } else if (controls.length && !event.shiftKey && target === controls.at(-1)) {
        event.preventDefault(); controls[0].focus();
      }
    }
  });

  all('.copy-button').forEach(button => button.addEventListener('click', async () => {
    const source = one('code', button.closest('.code-block'))?.textContent || '';
    try {
      if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(source);
      else {
        const field = document.createElement('textarea');
        field.value = source;
        field.style.position = 'fixed'; field.style.opacity = '0';
        body.append(field); field.select(); document.execCommand('copy'); field.remove();
      }
      button.textContent = 'Copied';
      setTimeout(() => { button.textContent = 'Copy'; }, 1800);
    } catch (_) { button.textContent = 'Select code to copy'; }
  }));

  // Build the TOC from content, retaining build-time ids so deep links survive.
  const toc = one('#page-toc');
  if (toc) {
    const headings = all('.article-content h2, .article-content h3');
    const used = new Set();
    headings.forEach(heading => {
      const base = heading.id || heading.textContent.toLowerCase().normalize('NFKD')
        .replace(/[^a-z0-9\s-]/g, '').trim().replace(/\s+/g, '-') || 'section';
      let id = base, suffix = 2;
      while (used.has(id)) id = `${base}-${suffix++}`;
      used.add(id); heading.id = id;
      const link = document.createElement('a');
      link.href = `#${id}`;
      link.textContent = heading.textContent;
      link.dataset.level = heading.tagName.slice(1);
      toc.append(link);
    });
    const links = all('a', toc);
    function reflectPosition() {
      let active = 0;
      headings.forEach((heading, position) => {
        if (heading.getBoundingClientRect().top <= 150) active = position;
      });
      links.forEach((link, position) => link.classList.toggle('active', position === active));
    }
    if (headings.length) {
      window.addEventListener('scroll', reflectPosition, {passive: true});
      reflectPosition();
    }
  }
})();

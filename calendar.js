(() => {
  const cfg = window.SALES_CALENDAR_CONFIG || {};
  const API_URL = cfg.apiUrl;
  const ALLOWED_LIST = Array.isArray(cfg.allowedWarehouses) ? cfg.allowedWarehouses : [];
  const NAME_TO_LABEL = cfg.nameToLabel || {};

  // ====== AJUSTES DE VELOCIDAD ======
  const CONCURRENCY = Number(cfg.concurrency || 10); // súbelo a 12–15 si tu API aguanta
  const TIMEOUT_MS = Number(cfg.timeoutMs || 10000);
  const RETRIES = Number(cfg.retries || 2);
  const RETRY_BASE_DELAY = Number(cfg.retryBaseDelayMs || 350); // ms
  const CACHE_TTL_HOURS = Number(cfg.cacheTtlHours || 8);

  const elGrid = document.getElementById("cal-grid");
  const elLabel = document.getElementById("cal-month-label");
  const elLoading = document.getElementById("cal-loading");
  const btnPrev = document.getElementById("cal-prev");
  const btnNext = document.getElementById("cal-next");
  const btnToday = document.getElementById("cal-today");

  if (!API_URL || !elGrid || !elLabel) return;

  const norm = (s) => String(s ?? "")
    .replace(/\u00A0/g, " ")
    .normalize("NFKC")
    .trim()
    .replace(/\s+/g, " ");

  const allowedNormToReal = new Map();
  for (const real of ALLOWED_LIST) allowedNormToReal.set(norm(real), real);

  const currency = new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: "MXN",
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  });

  const monthFmt = new Intl.DateTimeFormat("es-MX", { month: "long", year: "numeric" });

  let current = new Date();
  current = new Date(current.getFullYear(), current.getMonth(), 1);

  const toISO = (d) => {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
  };

  const monthKey = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
  const daysInMonth = (d) => new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
  const mondayIndex = (jsDay0Sun) => (jsDay0Sun + 6) % 7;

  const sleep = (ms) => new Promise(r => setTimeout(r, ms));

  function setLoading(on, text) {
    if (!elLoading) return;
    elLoading.style.display = on ? "block" : "none";
    if (on && text) elLoading.textContent = text;
  }

  async function fetchWithTimeout(url, ms = TIMEOUT_MS) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), ms);
    try {
      return await fetch(url, { signal: controller.signal });
    } finally {
      clearTimeout(timer);
    }
  }

  function makeZeroByWarehouse() {
    const obj = {};
    for (const n of ALLOWED_LIST) obj[n] = 0;
    return obj;
  }

  async function fetchDayTotals(dayDate) {
    const startDate = encodeURIComponent(toISO(dayDate) + "T00:00:00");
    const endDate = encodeURIComponent(toISO(dayDate) + "T23:59:59");
    const url = `${API_URL}?startDate=${startDate}&endDate=${endDate}`;

    for (let attempt = 0; attempt <= RETRIES; attempt++) {
      try {
        const res = await fetchWithTimeout(url);
        if (!res.ok) throw new Error("HTTP " + res.status);

        const data = await res.json();
        const byWarehouse = makeZeroByWarehouse();
        let totalAll = 0;

        for (const item of data || []) {
          const realName = allowedNormToReal.get(norm(item.warehouseName));
          if (!realName) continue;
          const t = Number(item.total || 0);
          byWarehouse[realName] = (byWarehouse[realName] || 0) + t;
          totalAll += t;
        }

        return { totalAll, byWarehouse };
      } catch (e) {
        // reintento con backoff
        if (attempt < RETRIES) {
          const delay = RETRY_BASE_DELAY * Math.pow(2, attempt);
          await sleep(delay);
          continue;
        }
        return { totalAll: 0, byWarehouse: makeZeroByWarehouse() };
      }
    }
  }

  // Concurrencia alta pero controlada
  async function runLimited(tasks, limit, onProgress) {
    const results = new Array(tasks.length);
    let idx = 0;
    let active = 0;
    let done = 0;

    return new Promise((resolve) => {
      const next = () => {
        if (done === tasks.length) return resolve(results);

        while (active < limit && idx < tasks.length) {
          const i = idx++;
          active++;
          tasks[i]()
            .then((r) => { results[i] = r; })
            .catch(() => { results[i] = null; })
            .finally(() => {
              active--;
              done++;
              onProgress?.(done, tasks.length);
              next();
            });
        }
      };
      next();
    });
  }

  function storageKeyMonth(key) {
    return `salesCal:${key}:v2:${norm(ALLOWED_LIST.join("|"))}`;
  }

  function loadMonthFromStorage(key) {
    try {
      const raw = localStorage.getItem(storageKeyMonth(key));
      if (!raw) return null;
      const parsed = JSON.parse(raw);
      if (!parsed?.data || !parsed?.savedAt) return null;

      const ttlMs = CACHE_TTL_HOURS * 3600 * 1000;
      if ((Date.now() - Number(parsed.savedAt)) > ttlMs) return null;

      return parsed.data;
    } catch {
      return null;
    }
  }

  function saveMonthToStorage(key, data) {
    try {
      localStorage.setItem(storageKeyMonth(key), JSON.stringify({ savedAt: Date.now(), data }));
    } catch {}
  }

  function isCurrentMonth(d) {
    const now = new Date();
    return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth();
  }

  async function loadMonth(d) {
    const key = monthKey(d);
    const cached = loadMonthFromStorage(key);
    if (cached) return cached;

    const dim = daysInMonth(d);

    // ✅ Si es mes actual, solo pedimos del 1 a HOY
    const endDay = isCurrentMonth(d) ? new Date().getDate() : dim;

    const results = {};
    const tasks = [];
    const isoList = [];

    for (let day = 1; day <= endDay; day++) {
      const dt = new Date(d.getFullYear(), d.getMonth(), day);
      const iso = toISO(dt);
      isoList.push(iso);
      tasks.push(async () => {
        const r = await fetchDayTotals(dt);
        results[iso] = r;
        return r;
      });
    }

    setLoading(true, "Cargando ventas del mes…");
    await runLimited(
      tasks,
      CONCURRENCY,
      (done, total) => setLoading(true, `Cargando ventas del mes… (${done}/${total})`)
    );
    setLoading(false);

    // ✅ Si pedimos solo hasta HOY, rellenamos el resto con 0 para que el calendario siempre tenga el mes completo
    for (let day = endDay + 1; day <= dim; day++) {
      const dt = new Date(d.getFullYear(), d.getMonth(), day);
      results[toISO(dt)] = { totalAll: 0, byWarehouse: makeZeroByWarehouse() };
    }

    saveMonthToStorage(key, results);
    return results;
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (m) => (
      m === "&" ? "&amp;" :
      m === "<" ? "&lt;" :
      m === ">" ? "&gt;" :
      m === '"' ? "&quot;" : "&#039;"
    ));
  }

  function renderCalendar(d, monthData) {
    elGrid.innerHTML = "";

    const label = monthFmt.format(d);
    elLabel.textContent = label.charAt(0).toUpperCase() + label.slice(1);

    const dim = daysInMonth(d);
    const first = new Date(d.getFullYear(), d.getMonth(), 1);
    const startIdx = mondayIndex(first.getDay());

    for (let i = 0; i < startIdx; i++) {
      const div = document.createElement("div");
      div.className = "day muted";
      div.innerHTML = `<div class="day-top"><div class="day-num"></div><div class="day-total"></div></div>`;
      elGrid.appendChild(div);
    }

    for (let day = 1; day <= dim; day++) {
      const dt = new Date(d.getFullYear(), d.getMonth(), day);
      const iso = toISO(dt);
      const info = monthData[iso] || { totalAll: 0, byWarehouse: makeZeroByWarehouse() };

      const linesHtml = ALLOWED_LIST.map((whName) => {
        const amount = Number(info.byWarehouse?.[whName] || 0);
        const tag = NAME_TO_LABEL[whName] || whName;
        const cls = amount === 0 ? 'style="opacity:.35"' : "";
        return `<div class="line" ${cls}>
                  <span class="tag">${escapeHtml(tag)}</span>
                  <span class="amt">${currency.format(amount)}</span>
                </div>`;
      }).join("");

      const div = document.createElement("div");
      div.className = "day";
      div.innerHTML = `
        <div class="day-top">
          <div class="day-num">${day}</div>
          <div class="day-total">${currency.format(Number(info.totalAll || 0))}</div>
        </div>
        <div class="lines">${linesHtml}</div>
      `;
      elGrid.appendChild(div);
    }
  }

  async function refresh() {
    const data = await loadMonth(current);
    renderCalendar(current, data);
  }

  btnPrev?.addEventListener("click", async () => {
    current = new Date(current.getFullYear(), current.getMonth() - 1, 1);
    await refresh();
  });

  btnNext?.addEventListener("click", async () => {
    current = new Date(current.getFullYear(), current.getMonth() + 1, 1);
    await refresh();
  });

  btnToday?.addEventListener("click", async () => {
    const now = new Date();
    current = new Date(now.getFullYear(), now.getMonth(), 1);
    await refresh();
  });

  refresh();
})();
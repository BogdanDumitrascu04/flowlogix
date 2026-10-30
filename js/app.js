/* Themes */
const AVAILABLE_THEMES = [
  "theme-flowlogix-classic",
  "theme-flowlogix-ocean",
  "theme-flowlogix-orange",
  "theme-flowlogix-dark",
  "theme-flowlogix-ice"
];

const THEME_LABELS = {
  "theme-flowlogix-classic": "FlowLogix Classic",
  "theme-flowlogix-ocean": "FlowLogix Ocean",
  "theme-flowlogix-orange": "FlowLogix Orange",
  "theme-flowlogix-dark": "FlowLogix Dark",
  "theme-flowlogix-ice": "FlowLogix Ice"
};

// Apply the saved theme before the first paint, so the default theme never flashes.
(function() {
  const savedTheme = localStorage.getItem("flowlogix_theme");
  if (savedTheme && AVAILABLE_THEMES.includes(savedTheme)) {
    document.documentElement.setAttribute("data-theme", savedTheme);
    document.body.classList.add(savedTheme);
  }
})();

const CONFIG = {
  themes: AVAILABLE_THEMES,
  storage: {
    theme: "flowlogix_theme",
    period: "flowlogix_period",
    warehouse: "flowlogix_warehouse",
    status: "flowlogix_status"
  },
  colors: {
    primary: "#2563eb",
    success: "#0f9f6e",
    warning: "#d97706",
    purple: "#7c3aed"
  },
  chart: {
    tension: 0.35,
    borderRadius: 8,
    responsive: true,
    maintainAspectRatio: false
  }
};

/* Theme helpers */
function applyTheme(themeName) {
  const body = document.body;
  const safeTheme = AVAILABLE_THEMES.includes(themeName)
    ? themeName
    : AVAILABLE_THEMES[0];

  AVAILABLE_THEMES.forEach((theme) => body.classList.remove(theme));

  body.classList.add(safeTheme);

  localStorage.setItem(CONFIG.storage.theme, safeTheme);

  return safeTheme;
}

function syncSettingsUI(themeName) {
  const themeCards = document.querySelectorAll("[data-theme-card]");
  const themeLabelEl = document.getElementById("activeThemeLabel");

  themeCards.forEach((card) => {
    const isActive = card.dataset.themeCard === themeName;
    card.classList.toggle("active-theme", isActive);
    const badge = card.querySelector(".theme-badge");
    if (badge) {
      badge.style.display = isActive ? "block" : "none";
    }
  });

  if (themeLabelEl && THEME_LABELS[themeName]) {
    themeLabelEl.textContent = THEME_LABELS[themeName];
  }
}

function changeTheme(themeName) {
  const appliedTheme = applyTheme(themeName);
  const page = document.body.dataset.page || "";

  if (page === "setari") {
    syncSettingsUI(appliedTheme);
  }
}

function initializeTheme() {
  const savedTheme = localStorage.getItem(CONFIG.storage.theme) || AVAILABLE_THEMES[0];
  const appliedTheme = applyTheme(savedTheme);
  const page = document.body.dataset.page || "";

  if (page === "setari") {
    syncSettingsUI(appliedTheme);
  }
}

/* Utilities */
function syncSelectWithStorage(element, storageKey, onChangeCallback) {
  if (!element) return;
  const saved = localStorage.getItem(storageKey);
  if (saved) element.value = saved;
  element.addEventListener("change", () => {
    localStorage.setItem(storageKey, element.value);
    onChangeCallback?.();
  });
}

function downloadCSV(csvContent, filename) {
  const bom = "﻿";
  const blob = new Blob([bom + csvContent], { type: "text/csv;charset=utf-8;" });
  const link = document.createElement("a");
  const url = URL.createObjectURL(blob);
  link.href = url;
  link.setAttribute("download", filename);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

/* Page setup */
document.addEventListener("DOMContentLoaded", () => {
  const body = document.body;
  const page = body.dataset.page || "";

  initializeTheme();

  const periodSelect = document.getElementById("periodSelect");
  const warehouseSelect = document.getElementById("warehouseSelect");
  const statusFilter = document.getElementById("statusFilter");
  const notificationToggle = document.getElementById("notificationToggle");
  const notificationDropdown = document.getElementById("notificationDropdown");
  const lastUpdate = document.getElementById("lastUpdate");

  const modal = document.getElementById("globalModal");
  const openModalBtn = document.getElementById("openModalBtn");
  const closeModalBtn = document.getElementById("closeModalBtn");
  const modalTitle = document.getElementById("modalTitle");
  const modalBody = document.getElementById("modalBody");

  /* Helpers */
  function formatTime() {
    const now = new Date();
    return now.toLocaleTimeString("ro-RO");
  }

  function setLastUpdate() {
    if (lastUpdate) {
      lastUpdate.textContent = formatTime();
    }
  }

  function openModal(title, content) {
    if (!modal) return;
    if (modalTitle && title) modalTitle.textContent = title;
    if (modalBody && content) modalBody.innerHTML = content;
    modal.classList.add("open");
  }

  function closeModal() {
    if (modal) modal.classList.remove("open");
  }

    setLastUpdate();

  /* Notifications */
  if (notificationToggle && notificationDropdown) {
    notificationToggle.addEventListener("click", (e) => {
      e.stopPropagation();
      notificationDropdown.classList.toggle("open");
    });

    notificationDropdown.addEventListener("click", (e) => e.stopPropagation());
    document.addEventListener("click", (e) => {
      if (!notificationDropdown.contains(e.target) && !notificationToggle.contains(e.target)) {
        notificationDropdown.classList.remove("open");
      }
    });
  }

  /* Modals */

  if (closeModalBtn) {
    closeModalBtn.addEventListener("click", closeModal);
  }

  if (modal) {
    modal.addEventListener("click", (e) => {
      if (e.target === modal) closeModal();
    });
  }

  document.querySelectorAll(".open-detail").forEach((btn) => {
    btn.addEventListener("click", () => {
      const ref = btn.dataset.title || "Element";
      openModal(
        `Detalii pentru ${ref}`,
        `
          <p><strong>Status curent:</strong> comanda este monitorizată deoarece există risc operațional asociat termenului ETA.</p>
          <p>Se recomandă verificarea stadiului de pregătire, a disponibilității resurselor și a eventualelor blocaje din flux.</p>
          <p class="footer-note">Acest tip de modal face dashboard-ul să pară mai realist și mai apropiat de un produs enterprise.</p>
        `
      );
    });
  });

  /* CSV export */
  function exportTableToCSV(tableId, filename = "export.csv") {
    const table = document.getElementById(tableId);
    if (!table) return;

    const rows = table.querySelectorAll("tr");
    const csv = [];

    rows.forEach((row) => {
      const cols = row.querySelectorAll("th, td");
      const rowData = [];
      cols.forEach((col) => {
        let text = col.innerText.replace(/\n/g, " ").trim();
        text = text.replace(/"/g, '""');
        rowData.push(`"${text}"`);
      });
      csv.push(rowData.join(";"));
    });

    downloadCSV(csv.join("\n"), filename);
  }

  const exportOrdersCsv = document.getElementById("exportOrdersCsv");
  if (exportOrdersCsv) {
    exportOrdersCsv.addEventListener("click", () => {
      exportTableToCSV("ordersTable", "flowlogix-comenzi-risc.csv");
    });
  }

  /* Table search and sorting */
  const ordersSearch = document.getElementById("ordersSearch");
  const ordersTableRows = document.querySelectorAll("#ordersTable tbody tr");
  if (ordersSearch) {
    ordersSearch.addEventListener("input", () => {
      const term = ordersSearch.value.toLowerCase();
      ordersTableRows.forEach((row) => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(term) ? "" : "none";
      });
    });
  }

  const ordersTable = document.getElementById("ordersTable");
  const ordersTableHeaders = ordersTable?.querySelectorAll("thead th[data-sort]");
  ordersTableHeaders?.forEach((header) => {
    header.addEventListener("click", () => {
      const tbody = ordersTable.querySelector("tbody");
      const index = Array.from(header.parentNode.children).indexOf(header);
      const rows = Array.from(tbody.querySelectorAll("tr"));
      const asc = !header.classList.contains("asc");

      document.querySelectorAll("#ordersTable thead th").forEach((th) => {
        th.classList.remove("asc", "desc");
      });
      header.classList.add(asc ? "asc" : "desc");

      rows.sort((a, b) => {
        const aText = a.children[index].innerText.trim();
        const bText = b.children[index].innerText.trim();
        return asc ? aText.localeCompare(bText, "ro") : bText.localeCompare(aText, "ro");
      });

      rows.forEach((row) => tbody.appendChild(row));
    });
  });

  /* Charts */
  function buildChart(id, type, data, options = {}) {
    const ctx = document.getElementById(id);
    if (!ctx || typeof Chart === "undefined") return null;
    return new Chart(ctx, { type, data, options });
  }

  function updateChartData(chart, newData) {
    if (!chart) return;
    chart.data.datasets.forEach((dataset, i) => {
      if (newData[i]) dataset.data = newData[i];
    });
    chart.update("none");
  }

  /* Selects and filters */
  if (periodSelect) {
    periodSelect.addEventListener("change", () => {
      const url = new URL(window.location.href);
      url.searchParams.set("period", periodSelect.value);
      window.location.href = url.toString();
    });
  }

  syncSelectWithStorage(warehouseSelect, CONFIG.storage.warehouse);
  syncSelectWithStorage(statusFilter, CONFIG.storage.status);

  const exportMainChart = document.getElementById("exportMainChart");
  if (exportMainChart) {
    exportMainChart.addEventListener("click", () => {
      const canvas = document.getElementById("dashboardTrendChart");
      if (!canvas) return;
      const link = document.createElement("a");
      link.download = "flowlogix-grafic-dashboard.png";
      link.href = canvas.toDataURL("image/png");
      link.click();
    });
  }

  /* Charts for each page */
  function createBasicCharts() {
    if (page === "inbound" || page === "analiza-inbound") {
      const daily = window.inboundMainChartData || {
        labels: ["Lun", "Mar", "Mie", "Joi", "Vin", "Sâm", "Dum"],
        data: [14, 18, 15, 20, 17, 13, 16]
      };

      const hourly = window.inboundHoursChartData || {
        labels: ["08:00", "10:00", "12:00", "14:00", "16:00", "18:00"],
        data: [6, 12, 14, 10, 7, 5]
      };

      buildChart("inboundMainChart", "bar", {
        labels: daily.labels,
        datasets: [{
          label: "Recepții",
          data: daily.data,
          backgroundColor: CONFIG.colors.primary,
          borderRadius: CONFIG.chart.borderRadius
        }]
      }, {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: true
          }
        },
        barPercentage: 0.85,
        categoryPercentage: 0.75
      });

      buildChart("inboundHoursChart", "line", {
        labels: hourly.labels,
        datasets: [{
          label: "Recepții / oră",
          data: hourly.data,
          borderColor: CONFIG.colors.warning,
          backgroundColor: "rgba(217,119,6,0.10)",
          tension: CONFIG.chart.tension,
          fill: true
        }]
      }, {
        responsive: true,
        maintainAspectRatio: false
      });
    }

    if (page === "pickpack" || page === "analiza-pickpack") {
      const statusChart = window.pickpackMainChartData || {
        labels: ["completed", "in_progress", "not_started"],
        data: [10, 5, 2]
      };

      const operatorChart = window.pickpackOperatorsChartData || {
        labels: ["Andrei", "Ioana", "Radu"],
        data: [8, 6, 4]
      };

      buildChart("pickpackMainChart", "bar", {
        labels: statusChart.labels,
        datasets: [{
          label: "Comenzi",
          data: statusChart.data,
          backgroundColor: CONFIG.colors.primary,
          borderRadius: CONFIG.chart.borderRadius
        }]
      }, {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: true
          }
        },
        barPercentage: 0.85,
        categoryPercentage: 0.75
      });

      buildChart("pickpackOperatorsChart", "bar", {
        labels: operatorChart.labels,
        datasets: [{
          label: "Comenzi / operator",
          data: operatorChart.data,
          backgroundColor: CONFIG.colors.warning,
          borderRadius: CONFIG.chart.borderRadius
        }]
      }, {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: true
          }
        },
        barPercentage: 0.85,
        categoryPercentage: 0.75
      });
    }

    if (page === "outbound" || page === "analiza-outbound") {
      const statusChart = window.outboundMainChartData || {
        labels: ["ready", "shipped", "delayed"],
        data: [5, 8, 2]
      };

      const delayChart = window.outboundDaysChartData || {
        labels: ["București", "Ploiești", "Constanța"],
        data: [0, 20, 5]
      };

      buildChart("outboundMainChart", "bar", {
        labels: statusChart.labels,
        datasets: [{
          label: "Comenzi",
          data: statusChart.data,
          backgroundColor: CONFIG.colors.primary,
          borderRadius: CONFIG.chart.borderRadius
        }]
      }, {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: true
          }
        },
        barPercentage: 0.85,
        categoryPercentage: 0.75
      });

      buildChart("outboundDaysChart", "bar", {
        labels: delayChart.labels,
        datasets: [{
          label: "Întârziere medie (minute)",
          data: delayChart.data,
          backgroundColor: CONFIG.colors.warning,
          borderRadius: CONFIG.chart.borderRadius
        }]
      }, {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: true
          }
        },
        barPercentage: 0.85,
        categoryPercentage: 0.75
      });
    }

    if (page === "dashboard-tura") {
      buildChart("shiftActivityChart", "line", {
        labels: ["08:00", "09:00", "10:00", "11:00", "12:00", "13:00", "14:00"],
        datasets: [
          {
            label: "Task-uri active",
            data: [18, 22, 28, 31, 27, 34, 38],
            borderColor: CONFIG.colors.warning,
            backgroundColor: "rgba(217,119,6,0.10)",
            tension: CONFIG.chart.tension,
            fill: true
          },
          {
            label: "Task-uri finalizate",
            data: [12, 16, 19, 22, 21, 25, 29],
            borderColor: CONFIG.colors.success,
            backgroundColor: "rgba(15,159,110,0.08)",
            tension: CONFIG.chart.tension,
            fill: true
          }
        ]
      }, {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: "top" }
        }
      });

      buildChart("shiftTaskChart", "doughnut", {
        labels: ["Inbound", "Picking", "Packing", "Outbound"],
        datasets: [{
          data: [22, 34, 19, 25],
          backgroundColor: [CONFIG.colors.primary, CONFIG.colors.warning, CONFIG.colors.success, CONFIG.colors.purple],
          borderWidth: 0
        }]
      }, {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: "bottom" }
        }
      });
    }

    if (page === "dashboard-depozit" || page === "analiza-depozit") {
      const flowChart = window.depotFlowChartData || {
        labels: [],
        data: []
      };

      const capacityChart = window.depotCapacityChartData || {
        labels: [],
        data: []
      };

      const trendChart = window.depotTrendChartData || {
        labels: [],
        data: []
      };

      buildChart("depotFlowChart", "bar", {
        labels: flowChart.labels,
        datasets: [{
          label: "Volum",
          data: flowChart.data,
          backgroundColor: [CONFIG.colors.primary, CONFIG.colors.warning, CONFIG.colors.success],
          borderRadius: 10
        }]
      }, {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: true
          }
        },
        plugins: {
          legend: { display: false }
        },
        barPercentage: 0.85,
        categoryPercentage: 0.75
      });

      buildChart("depotCapacityChart", "doughnut", {
        labels: capacityChart.labels,
        datasets: [{
          data: capacityChart.data,
          backgroundColor: [CONFIG.colors.primary, CONFIG.colors.warning, CONFIG.colors.success, CONFIG.colors.purple],
          borderWidth: 0
        }]
      }, {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: "bottom" }
        }
      });

      buildChart("depotTrendChart", "line", {
        labels: trendChart.labels,
        datasets: [{
          label: "Evenimente operaționale",
          data: trendChart.data,
          borderColor: CONFIG.colors.primary,
          backgroundColor: "rgba(37,99,235,0.10)",
          tension: CONFIG.chart.tension,
          fill: true
        }]
      }, {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: "top" }
        }
      });
    }

    if (page === "dashboard-manager") {
      const statusChart = window.managerStatusChartData || {
        labels: ["În termen", "Întârziate", "În lucru"],
        data: [0, 0, 0]
      };

      const flowChart = window.managerFlowChartData || {
        labels: ["Inbound", "Pick / Pack", "Outbound"],
        data: [0, 0, 0]
      };

      buildChart("managerStatusChart", "doughnut", {
        labels: statusChart.labels,
        datasets: [{
          data: statusChart.data,
          backgroundColor: [CONFIG.colors.success, "#dc2626", CONFIG.colors.warning],
          borderWidth: 0
        }]
      }, {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: "bottom" }
        }
      });

      buildChart("managerFlowChart", "bar", {
        labels: flowChart.labels,
        datasets: [{
          label: "Operațiuni totale",
          data: flowChart.data,
          backgroundColor: [CONFIG.colors.primary, CONFIG.colors.warning, CONFIG.colors.success],
          borderRadius: CONFIG.chart.borderRadius
        }]
      }, {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: { beginAtZero: true }
        },
        plugins: {
          legend: { display: false }
        },
        barPercentage: 0.6,
        categoryPercentage: 0.7
      });
    }
  }

  /* Reports */
  if (page === "rapoarte") {
    const printReportBtn = document.getElementById("printReportBtn");
    const previewButtons = document.querySelectorAll(".preview-report-btn");
    const exportButtons = document.querySelectorAll(".export-report-btn");
    const reportPeriodSelect = document.getElementById("reportPeriodSelect");
    const reportAreaSelect = document.getElementById("reportAreaSelect");
    const reportDetailSelect = document.getElementById("reportDetailSelect");

    function printMainOnly() {
      const pageShell = document.querySelector(".page-shell");
      if (!pageShell) return;

      const printClone = pageShell.cloneNode(true);

      const breadcrumb = printClone.querySelector(".breadcrumb");
      if (breadcrumb) breadcrumb.remove();

      const topbarRight = printClone.querySelector(".topbar-right");
      if (topbarRight) topbarRight.remove();

      printClone.querySelectorAll(".actions-inline").forEach((el) => el.remove());
      printClone.querySelectorAll(".control-button").forEach((el) => el.remove());
      printClone.querySelectorAll(".modal").forEach((el) => el.remove());
      printClone.querySelectorAll("script").forEach((el) => el.remove());

      const printWindow = window.open("", "_blank", "width=1200,height=800");
      if (!printWindow) return;

      printWindow.document.write(`
        <!DOCTYPE html>
        <html lang="ro">
        <head>
          <meta charset="utf-8">
          <title>FlowLogix | Raport</title>
          <style>
            body {
              margin: 0;
              padding: 24px;
              background: #ffffff;
              color: #111827;
              font-family: Arial, sans-serif;
            }
            .page-shell {
              width: 100%;
              max-width: 100%;
            }
            .topbar {
              margin-bottom: 24px;
              padding-bottom: 14px;
              border-bottom: 2px solid #e5e7eb;
            }
            .topbar-left h1 {
              margin: 0 0 8px 0;
              font-size: 28px;
            }
            .topbar-left p {
              margin: 0;
              font-size: 14px;
              color: #4b5563;
            }
            .report-grid,
            .settings-grid {
              display: block;
            }
            .card {
              border: 1px solid #d1d5db;
              border-radius: 12px;
              padding: 18px;
              margin-bottom: 18px;
              background: #ffffff;
              box-sizing: border-box;
              page-break-inside: avoid;
              break-inside: avoid;
            }
            .card-header {
              display: flex;
              justify-content: space-between;
              align-items: flex-start;
              gap: 12px;
              margin-bottom: 10px;
            }
            .card-title-wrap h3 {
              margin: 0 0 6px 0;
              font-size: 20px;
            }
            .card-title-wrap p,
            .text-muted,
            .footer-note {
              margin: 0;
              color: #4b5563;
              font-size: 14px;
              line-height: 1.5;
            }
            .card-tag {
              display: inline-block;
              padding: 6px 10px;
              border: 1px solid #d1d5db;
              border-radius: 999px;
              font-size: 12px;
              font-weight: bold;
              color: #111827;
              background: #f3f4f6;
              white-space: nowrap;
            }
            .setting-row {
              display: flex;
              justify-content: space-between;
              align-items: center;
              gap: 16px;
              padding: 12px 0;
              border-bottom: 1px solid #e5e7eb;
            }
            .setting-row:last-child {
              border-bottom: none;
            }
            .setting-row strong {
              display: block;
              margin-bottom: 4px;
            }
            .setting-row select {
              min-width: 180px;
              padding: 8px 10px;
              border: 1px solid #d1d5db;
              border-radius: 8px;
              background: #fff;
            }
            .progress-row {
              margin-bottom: 16px;
            }
            .row-top {
              display: flex;
              justify-content: space-between;
              align-items: center;
              margin-bottom: 6px;
              font-size: 14px;
            }
            .progress-track {
              width: 100%;
              height: 10px;
              background: #e5e7eb;
              border-radius: 999px;
              overflow: hidden;
            }
            .progress-fill {
              height: 100%;
              background: #9ca3af;
              border-radius: 999px;
            }
            @page {
              size: A4;
              margin: 12mm;
            }
          </style>
        </head>
        <body></body>
        </html>
      `);

      printWindow.document.close();
      printWindow.document.body.appendChild(printClone);

      setTimeout(() => {
        printWindow.focus();
        printWindow.print();
      }, 300);
    }

    if (printReportBtn) {
      printReportBtn.addEventListener("click", () => {
        printMainOnly();
      });
    }

    previewButtons.forEach((btn) => {
      btn.addEventListener("click", () => {
        const title = btn.dataset.reportTitle || "Raport";
        const desc = btn.dataset.reportDesc || "";
        const text = btn.dataset.reportText || "";
        const tag = btn.dataset.reportTag || "";
        const role = btn.dataset.reportRole || "default";

        const selectedPeriod = reportPeriodSelect ? reportPeriodSelect.options[reportPeriodSelect.selectedIndex].text : "Nespecificată";
        const selectedArea = reportAreaSelect ? reportAreaSelect.options[reportAreaSelect.selectedIndex].text : "Toate modulele";
        const selectedDetail = reportDetailSelect ? reportDetailSelect.options[reportDetailSelect.selectedIndex].text : "Executiv";

        let roleLabel = "Utilizator";
        if (role === "tura") roleLabel = "Șef de tură";
        if (role === "depozit") roleLabel = "Șef de depozit";
        if (role === "manager") roleLabel = "Manager logistic";

        openModal(
          `Previzualizare – ${title}`,
          `
            <p><strong>Tip raport:</strong> ${tag}</p>
            <p><strong>Rol activ:</strong> ${roleLabel}</p>
            <p><strong>Descriere:</strong> ${desc}</p>
            <p><strong>Utilitate:</strong> ${text}</p>
            <div class="progress-list" style="margin-top:16px;">
              <div class="progress-row">
                <div class="row-top">
                  <span>Perioadă selectată</span>
                  <strong>${selectedPeriod}</strong>
                </div>
                <div class="progress-track"><div class="progress-fill" style="width:100%"></div></div>
              </div>
              <div class="progress-row">
                <div class="row-top">
                  <span>Arie inclusă</span>
                  <strong>${selectedArea}</strong>
                </div>
                <div class="progress-track"><div class="progress-fill" style="width:92%"></div></div>
              </div>
              <div class="progress-row">
                <div class="row-top">
                  <span>Nivel detaliu</span>
                  <strong>${selectedDetail}</strong>
                </div>
                <div class="progress-track"><div class="progress-fill" style="width:85%"></div></div>
              </div>
            </div>
            <p class="footer-note" style="margin-top:16px;">Aceasta este o previzualizare demo a raportului. În varianta finală, exportul poate fi conectat la date reale și șabloane dedicate.</p>
          `
        );
      });
    });

    exportButtons.forEach((btn) => {
      btn.addEventListener("click", () => {
        const exportType = btn.dataset.exportType || "pdf";
        const selectedPeriod = reportPeriodSelect ? reportPeriodSelect.value : "7d";
        const selectedArea = reportAreaSelect ? reportAreaSelect.value : "all";
        const selectedDetail = reportDetailSelect ? reportDetailSelect.value : "executive";

        if (exportType === "pdf") {
          printMainOnly();
        } else {
          const url = `export_report.php?period=${encodeURIComponent(selectedPeriod)}&area=${encodeURIComponent(selectedArea)}&detail=${encodeURIComponent(selectedDetail)}`;
          window.open(url, "_blank");
        }
      });
    });
  }

  /* Settings page */
  if (page === "setari") {
    const themeSelect = document.getElementById("themeSelect");
    const themeCards = document.querySelectorAll("[data-theme-card]");

    if (themeSelect) {
      themeSelect.addEventListener("change", () => {
        changeTheme(themeSelect.value);
      });
    }

    themeCards.forEach((card) => {
      card.addEventListener("click", () => {
        const themeName = card.dataset.themeCard;
        changeTheme(themeName);
      });
    });
  }

    createBasicCharts();
});

/* Keep the theme in sync across open tabs */
window.addEventListener("storage", (event) => {
  if (event.key === CONFIG.storage.theme && event.newValue) {
    applyTheme(event.newValue);
    const page = document.body.dataset.page || "";
    if (page === "setari") {
      syncSettingsUI(event.newValue);
    }
  }
});

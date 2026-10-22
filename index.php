<?php
// Redirecționează la login.php
header("Location: login.php");
exit;
?>

  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="css/sidebar.css?v=13">
  <link rel="stylesheet" href="css/styles.css?v=13">
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="js/app.js?v=<?php echo time(); ?>" defer></script>
</head>
<body data-page="dashboard">
  <?php include 'sidebar.php'; ?>

  <main class="main">
    <div class="page-shell">
      <div class="breadcrumb">
        <i class='bx bx-home-alt'></i>
        <span>FlowLogix</span>
        <i class='bx bx-chevron-right'></i>
        <strong>Dashboard</strong>
      </div>

      <div class="topbar">
        <div class="topbar-left">
          <h1>Dashboard operațional</h1>
          <p>Privire de ansamblu asupra fluxurilor logistice și performanței operaționale.</p>
        </div>

        <div class="topbar-right">
          <select class="control-select" id="periodSelect">
            <option value="today">Astăzi</option>
            <option value="7d" selected>Ultimele 7 zile</option>
            <option value="30d">Ultimele 30 zile</option>
            <option value="month">Luna curentă</option>
            <option value="year">Anul curent</option>
          </select>

          <select class="control-select" id="warehouseSelect">
            <option value="all">Toate depozitele</option>
            <option value="est">Depozit Est</option>
            <option value="vest">Depozit Vest</option>
            <option value="central">Depozit Central</option>
          </select>

          <select class="control-select" id="statusFilter">
            <option value="all">Toate statusurile</option>
            <option value="normal">Normal</option>
            <option value="risk">În risc</option>
            <option value="critical">Critic</option>
          </select>

          <button class="control-button" id="liveToggle">
            <i class='bx bx-radio-circle-marked'></i>
            <span>Live mode</span>
          </button>

          <div class="notification-panel">
            <button class="control-button" id="notificationToggle" aria-label="Deschide notificările">
              <i class='bx bx-bell'></i>
              <span>Notificări</span>
            </button>

            <div class="notification-dropdown" id="notificationDropdown">
              <div class="card-header" style="margin-bottom:0;">
                <div class="card-title-wrap">
                  <h3>Centru notificări</h3>
                  <p>Evenimente recente și alerte operaționale</p>
                </div>
                <span class="card-tag tag-danger">4 active</span>
              </div>

              <div class="notification-list">
                <div class="notification-item">
                  <div>
                    <strong>Rampă 2 blocată</strong>
                    <p>Întârziere de 18 minute la recepție.</p>
                  </div>
                  <span class="small-badge badge-danger">Critic</span>
                </div>

                <div class="notification-item">
                  <div>
                    <strong>Comenzi în risc outbound</strong>
                    <p>3 comenzi pot depăși ETA în următoarele 2 ore.</p>
                  </div>
                  <span class="small-badge badge-warning">Atenție</span>
                </div>

                <div class="notification-item">
                  <div>
                    <strong>Picking sub target</strong>
                    <p>Productivitatea a scăzut cu 6% pe schimbul 2.</p>
                  </div>
                  <span class="small-badge badge-warning">Monitorizare</span>
                </div>
              </div>
            </div>
          </div>

          <button class="control-button primary" id="openModalBtn">
            <i class='bx bx-show-alt'></i>
            <span>Detalii KPI</span>
          </button>
        </div>
      </div>

      <div class="status-strip">
        <div class="status-pill">
          <div class="meta">
            <span>Status sistem</span>
            <strong>Operațional</strong>
          </div>
          <span class="status-dot dot-success"></span>
        </div>

        <div class="status-pill">
          <div class="meta">
            <span>Ultima actualizare</span>
            <strong id="lastUpdate">14:20:35</strong>
          </div>
          <span class="status-dot dot-primary"></span>
        </div>

        <div class="status-pill">
          <div class="meta">
            <span>Nivel alertă</span>
            <strong>Moderat</strong>
          </div>
          <span class="status-dot dot-warning"></span>
        </div>

        <div class="status-pill">
          <div class="meta">
            <span>Nivel performanță global</span>
            <strong>96.8%</strong>
          </div>
          <span class="status-dot dot-success"></span>
        </div>
      </div>

      <!-- Alerte ascunse pentru consistență cu inbound.php -->
      <?php /*
      <div class="alert-grid">
        <div class="alert-card warning">
          <h4>Presiune pe zona inbound</h4>
          <p>Volumul de recepții este peste medie în intervalul 09:00–12:00. Recomandată redistribuirea temporară a resurselor.</p>
        </div>

        <div class="alert-card danger">
          <h4>Risc de întârziere outbound</h4>
          <p>Două curse planificate pentru astăzi se apropie de pragul critic. Verifică pregătirea documentelor și a rampelor.</p>
        </div>

        <div class="alert-card success">
          <h4>Performanță pick/pack stabilă</h4>
          <p>Productivitatea operatorilor de pe schimbul 1 este peste target și suportă absorbția backlogului curent.</p>
        </div>
      </div>
      */ ?>

      <div class="cards-grid kpi-grid">
        <div class="card kpi-card" data-kpi="incoming">
          <div class="kpi-top">
            <span class="label">Comenzi intrate</span>
            <span class="kpi-icon"><i class='bx bx-import'></i></span>
          </div>
          <div class="kpi-value" id="kpiIncoming">720</div>
          <div class="kpi-subtext">Volum total în perioada selectată</div>
          <div class="kpi-trend positive" id="kpiIncomingTrend">
            <i class='bx bx-up-arrow-alt'></i> +6.4% vs perioada anterioară
          </div>
        </div>

        <div class="card kpi-card" data-kpi="processed">
          <div class="kpi-top">
            <span class="label">Procesate complet</span>
            <span class="kpi-icon"><i class='bx bx-check-shield'></i></span>
          </div>
          <div class="kpi-value"><span id="kpiProcessed">698</span> / <span id="kpiTotal">720</span></div>
          <div class="kpi-subtext"><span id="kpiProcessedPct">97%</span> dintre comenzi sunt finalizate</div>
          <div class="kpi-progress">
            <div class="kpi-progress-bar" id="kpiProcessedBar" style="width:97%;"></div>
          </div>
          <div class="kpi-trend positive" id="kpiProcessedTrend">
            <i class='bx bx-up-arrow-alt'></i> +2.1% eficiență operațională
          </div>
        </div>

        <div class="card kpi-card" data-kpi="ontime">
          <div class="kpi-top">
            <span class="label">Livrate la timp</span>
            <span class="kpi-icon"><i class='bx bx-timer'></i></span>
          </div>
          <div class="kpi-value" id="kpiOnTime">95%</div>
          <div class="kpi-subtext">Comenzi respectând ETA / nivel performanță</div>
          <div class="kpi-progress">
            <div class="kpi-progress-bar" id="kpiOnTimeBar" style="width:95%;"></div>
          </div>
          <div class="kpi-trend positive" id="kpiOnTimeTrend">
            <i class='bx bx-up-arrow-alt'></i> +1.2% rată OTIF
          </div>
        </div>

        <div class="card kpi-card" data-kpi="backlog">
          <div class="kpi-top">
            <span class="label">Backlog operațional</span>
            <span class="kpi-icon"><i class='bx bx-time-five'></i></span>
          </div>
          <div class="kpi-value" id="kpiBacklog">22</div>
          <div class="kpi-subtext">Comenzi rămase în așteptare</div>
          <div class="kpi-trend negative" id="kpiBacklogTrend">
            <i class='bx bx-up-arrow-alt'></i> +3 față de ieri
          </div>
        </div>
      </div>

      <div class="cards-grid two-col">
        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Trend: intrate vs procesate</h3>
              <p>Evoluția volumului pe ultimele intervale.</p>
            </div>
            <button class="card-icon-btn" id="exportMainChart">
              <i class='bx bx-download'></i>
            </button>
          </div>
          <div class="chart-wrap">
            <canvas id="dashboardTrendChart"></canvas>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Distribuție operațională</h3>
              <p>Pondere pe etape: inbound, pick/pack și outbound.</p>
            </div>
            <span class="card-tag tag-primary">Flow</span>
          </div>
          <div class="chart-wrap chart-sm">
            <canvas id="dashboardFlowChart"></canvas>
          </div>
        </div>
      </div>

      <div class="cards-grid three-col">
        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Rezumat operațional</h3>
              <p>Indicatori cheie observați în perioada curentă.</p>
            </div>
          </div>

          <div class="summary-list">
            <div class="summary-item">
              <div>
                <strong>Recepții în fereastră</strong>
                <p>86% dintre recepții au fost descărcate în intervalul planificat.</p>
              </div>
              <span class="metric-badge badge-success">+4%</span>
            </div>

            <div class="summary-item">
              <div>
                <strong>Picking sub target</strong>
                <p>Schimbul 2 necesită sprijin temporar în zona de picking rapid.</p>
              </div>
              <span class="metric-badge badge-warning">Atenție</span>
            </div>

            <div class="summary-item">
              <div>
                <strong>Capacitate outbound</strong>
                <p>Rampa 4 funcționează aproape de pragul maxim de utilizare.</p>
              </div>
              <span class="metric-badge badge-danger">97%</span>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Flux operațional</h3>
              <p>Evenimente cheie din lanțul logistic intern.</p>
            </div>
          </div>

          <div class="timeline">
            <div class="timeline-item">
              <div>
                <div class="timeline-stage"><i class='bx bx-log-in-circle'></i> Inbound</div>
                <p>Recepția lotului FR-102 a fost finalizată în 42 minute.</p>
              </div>
              <span class="small-badge badge-success">Finalizat</span>
            </div>

            <div class="timeline-item">
              <div>
                <div class="timeline-stage"><i class='bx bx-package'></i> Pick / Pack</div>
                <p>Zona B are 14 comenzi în procesare și 2 excepții de etichetare.</p>
              </div>
              <span class="small-badge badge-warning">În lucru</span>
            </div>

            <div class="timeline-item">
              <div>
                <div class="timeline-stage"><i class='bx bx-log-out-circle'></i> Outbound</div>
                <p>3 transporturi sunt pregătite pentru încărcare în următoarea oră.</p>
              </div>
              <span class="small-badge badge-primary">Programat</span>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Acțiuni recomandate</h3>
              <p>Sugestii bazate pe situația operațională curentă.</p>
            </div>
          </div>

          <div class="recommend-list">
            <div class="recommend-item">
              <div>
                <strong>Redistribuie 1 operator</strong>
                <p>Mută temporar un operator din packing în recepție între 10:00–12:00.</p>
              </div>
              <span class="small-badge badge-warning">Prioritar</span>
            </div>

            <div class="recommend-item">
              <div>
                <strong>Verifică comanda CL-2098</strong>
                <p>Există risc de depășire ETA din cauza pregătirii incomplete.</p>
              </div>
              <span class="small-badge badge-danger">Critic</span>
            </div>

            <div class="recommend-item">
              <div>
                <strong>Optimizează încărcarea</strong>
                <p>Rampa 4 poate fi degrevată prin mutarea unei curse pe rampa 1.</p>
              </div>
              <span class="small-badge badge-primary">Propunere</span>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title-wrap">
            <h3>Comenzi cu risc operațional</h3>
            <p>Tabel interactiv cu căutare, sortare și export CSV.</p>
          </div>
          <span class="card-tag tag-warning">6 în risc</span>
        </div>

        <div class="table-toolbar">
          <input type="text" class="control-input table-search" id="ordersSearch" placeholder="Caută după comandă, client sau status">
          <div class="actions-inline">
            <button class="control-button" id="exportOrdersCsv">
              <i class='bx bx-spreadsheet'></i>
              <span>Export CSV</span>
            </button>
          </div>
        </div>

        <div class="table-scroll">
          <table class="data-table" id="ordersTable">
            <thead>
              <tr>
                <th data-sort="order">Comandă</th>
                <th data-sort="client">Client</th>
                <th data-sort="zone">Zonă</th>
                <th data-sort="eta">ETA</th>
                <th data-sort="status">Status</th>
                <th>Acțiune</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>CL-2098</td>
                <td>Retail Nord</td>
                <td>A2</td>
                <td>16:30</td>
                <td><span class="small-badge badge-danger">Critic</span></td>
                <td><button class="link-btn open-detail" data-title="CL-2098">Vezi detalii</button></td>
              </tr>
              <tr>
                <td>CL-2103</td>
                <td>Distrib Logistic</td>
                <td>B1</td>
                <td>17:10</td>
                <td><span class="small-badge badge-warning">În risc</span></td>
                <td><button class="link-btn open-detail" data-title="CL-2103">Vezi detalii</button></td>
              </tr>
              <tr>
                <td>CL-2110</td>
                <td>Fresh Market</td>
                <td>C4</td>
                <td>18:00</td>
                <td><span class="small-badge badge-warning">Monitorizare</span></td>
                <td><button class="link-btn open-detail" data-title="CL-2110">Vezi detalii</button></td>
              </tr>
              <tr>
                <td>CL-2124</td>
                <td>Urban Shop</td>
                <td>A1</td>
                <td>18:20</td>
                <td><span class="small-badge badge-success">Stabil</span></td>
                <td><button class="link-btn open-detail" data-title="CL-2124">Vezi detalii</button></td>
              </tr>
              <tr>
                <td>CL-2131</td>
                <td>Metro Supply</td>
                <td>D2</td>
                <td>19:00</td>
                <td><span class="small-badge badge-warning">În risc</span></td>
                <td><button class="link-btn open-detail" data-title="CL-2131">Vezi detalii</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="footer-note">
        FlowLogix simulează monitorizarea operațională a unui depozit, cu accent pe KPI logistici, identificarea blocajelor și sprijinirea deciziilor rapide.
      </div>
    </div>
  </main>

  <div class="modal" id="globalModal">
    <div class="modal-dialog">
      <div class="modal-header">
        <h3 class="modal-title" id="modalTitle">Detaliu KPI</h3>
      </div>
      <div class="modal-body" id="modalBody">
        <p><strong>Indicator curent:</strong> performanță generală stabilă.</p>
        <p>În perioada selectată se observă o presiune moderată în zona inbound și o nevoie punctuală de echilibrare între picking și packing.</p>
        <div class="progress-list">
          <div class="progress-row">
            <div class="row-top">
              <span>Nivel performanță logistic</span>
              <strong>96%</strong>
            </div>
            <div class="progress-track"><div class="progress-fill" style="width:96%"></div></div>
          </div>
          <div class="progress-row">
            <div class="row-top">
              <span>Capacitate utilizată</span>
              <strong>88%</strong>
            </div>
            <div class="progress-track"><div class="progress-fill" style="width:88%"></div></div>
          </div>
          <div class="progress-row">
            <div class="row-top">
              <span>Rată de finalizare</span>
              <strong>97%</strong>
            </div>
            <div class="progress-track"><div class="progress-fill" style="width:97%"></div></div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="control-button" id="closeModalBtn">Închide</button>
      </div>
    </div>
  </div>
</body>
</html>
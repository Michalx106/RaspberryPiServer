const query = (selector) => document.querySelector(selector);
const queryAll = (selector) => Array.from(document.querySelectorAll(selector));

export function collectDomElements() {
  return {
    time: query('[data-role="server-time"]'),
    cpuTemperature: query('[data-role="cpu-temperature"]'),
    memoryUsage: query('[data-role="memory-usage"]'),
    diskUsage: query('[data-role="disk-usage"]'),
    systemLoad: query('[data-role="system-load"]'),
    uptime: query('[data-role="uptime"]'),
    serviceList: query('[data-role="service-list"]'),
    refreshLabel: query('[data-role="refresh-label"]'),
    refreshButton: query('[data-role="refresh-button"]'),
    refreshContainer: query('[data-role="refresh-container"]'),
    statusNote: query('[data-role="status-note"]'),
    historyContainer: query('[data-role="history-container"]'),
    historyChartWrapper: query('[data-role="history-chart-wrapper"]'),
    historyChart: query('[data-role="history-chart"]'),
    historyTitle: query('[data-role="history-title"]'),
    historyMetricButtons: queryAll('[data-role="history-metric"]'),
    historyEmpty: query('[data-role="history-empty"]'),
    themeToggle: query('[data-role="theme-toggle"]'),
    tabs: query('[data-role="tabs"]'),
    tabButtons: queryAll('[data-role="tab"]'),
    tabPanels: queryAll('[data-role="tab-panel"]'),
    shellyPanel: query('[data-tab-panel="shelly"]'),
    shellyList: query('[data-role="shelly-list"]'),
    shellyError: query('[data-role="shelly-error"]'),
    shellyMessage: query('[data-role="shelly-message"]'),
    shellyReload: query('[data-role="shelly-reload"]'),
    shellyLastUpdate: query('[data-role="shelly-last-update"]'),
  };
}

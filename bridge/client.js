(function () {
  // Turns `x-toolbar` and `x-toolbar-summary` response headers into `laravel-toolbar:update`
  // events, as the package's own interceptors do. T3 Code's preload listens for them.
  if (window.__PHP_DATA_BRIDGE__) return;
  window.__PHP_DATA_BRIDGE__ = true;

  function emit(data) {
    window.dispatchEvent(new CustomEvent("laravel-toolbar:update", { detail: { data: data } }));
  }

  function read(get) {
    try {
      var full = get("x-toolbar");
      if (full && full.length <= 32768) return emit(JSON.parse(atob(full)));
      var summary = get("x-toolbar-summary");
      if (summary && summary.length <= 32768) {
        var parsed = JSON.parse(atob(summary));
        emit({ request_id: parsed.request_id, history_row: parsed.history_row, history_available: parsed.history_available });
      }
    } catch (error) {}
  }

  function accepts(url) {
    try {
      var parsed = new URL(url);
      return parsed.origin === window.location.origin && !/^\/_toolbar\/requests\//.test(parsed.pathname);
    } catch (error) { return false; }
  }

  var originalFetch = window.fetch;
  window.fetch = function (input) {
    return originalFetch.apply(this, arguments).then(function (response) {
      if (accepts(response.url)) {
        read(function (name) { return response.headers.get(name); });
      }
      return response;
    });
  };

  var originalSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.send = function () {
    var xhr = this;
    xhr.addEventListener("load", function () {
      if (accepts(xhr.responseURL)) {
        read(function (name) { return xhr.getResponseHeader(name); });
      }
    });
    return originalSend.apply(this, arguments);
  };
})();

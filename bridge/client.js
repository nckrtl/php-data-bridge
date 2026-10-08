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
      if (full) return emit(JSON.parse(atob(full)));
      var summary = get("x-toolbar-summary");
      if (summary) {
        var parsed = JSON.parse(atob(summary));
        emit({ request_id: parsed.request_id, history_row: parsed.history_row });
      }
    } catch (error) {}
  }

  var originalFetch = window.fetch;
  window.fetch = function (input) {
    var url = typeof input === "string" ? input : input instanceof URL ? input.href : input.url;
    return originalFetch.apply(this, arguments).then(function (response) {
      if (url.indexOf("/_toolbar/requests/") === -1) {
        read(function (name) { return response.headers.get(name); });
      }
      return response;
    });
  };

  var originalSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.send = function () {
    var xhr = this;
    xhr.addEventListener("load", function () {
      read(function (name) { return xhr.getResponseHeader(name); });
    });
    return originalSend.apply(this, arguments);
  };
})();

/**
 * B2BGro workspace script (1.5.0). Only what the server cannot do on its own; every form still
 * works without it. Plain JavaScript, no library.
 *
 *  1. Product search: a field marked data-wb-pick becomes a list of matches as you type (code, name
 *     or barcode), each with this customer's price, where it comes from and what is in stock. The
 *     arrow keys move, Enter chooses, Escape closes. With data-wb-pick-into the choice is added to
 *     a textarea of lines (purchase orders) instead.
 */
(function () {
  "use strict";
  var money = function (v) { return Number(v).toLocaleString("en-ZA", { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var qty = function (v) { return Number(v).toLocaleString("en-ZA", { maximumFractionDigits: 2 }); };

  function picker(box) {
    var url = box.getAttribute("data-wb-pick"), into = box.getAttribute("data-wb-pick-into");
    var input = box.querySelector("input[type=text]"), hidden = box.querySelector("input[type=hidden]");
    var list = box.querySelector(".wb-pick-list"), note = box.querySelector(".wb-pick-note");
    var items = [], active = -1, timer = null, seq = 0;

    function close() { list.hidden = true; input.setAttribute("aria-expanded", "false"); input.removeAttribute("aria-activedescendant"); active = -1; }
    function mark(i) {
      var opts = list.querySelectorAll("[role=option]");
      opts.forEach(function (o, n) { o.setAttribute("aria-selected", n === i ? "true" : "false"); });
      active = i;
      if (opts[i]) { input.setAttribute("aria-activedescendant", opts[i].id); opts[i].scrollIntoView({ block: "nearest" }); }
    }
    function describe(p) {
      var bits = [];
      if (p.price !== undefined) bits.push("R " + money(p.price) + (p.note ? " (" + p.note + ")" : ""));
      if (p.available !== undefined) bits.push(qty(p.available) + " available");
      return bits.join(" · ");
    }
    function choose(i) {
      var p = items[i]; if (!p) return;
      if (into) {
        var ta = document.getElementById(into);
        if (ta) { ta.value = (ta.value.replace(/\s+$/, "") + (ta.value.trim() ? "\n" : "") + p.sku + ", 1"); ta.focus(); }
        input.value = ""; hidden.value = "";
        note.textContent = p.sku + " added. Change the quantity on its line.";
      } else {
        hidden.value = p.id; input.value = p.sku + " · " + p.name;
        note.textContent = describe(p) + (p.warn ? " " + p.warn : "");
        note.classList.toggle("is-warn", !!p.warn);
        var q = box.parentNode.querySelector("input[name=qty]"); if (q) { q.focus(); q.select(); }
      }
      close();
    }
    function draw() {
      list.innerHTML = "";
      if (!items.length) { close(); note.textContent = input.value.trim() ? "No product matches." : ""; return; }
      items.forEach(function (p, i) {
        var li = document.createElement("li");
        li.id = input.id + "-o" + i; li.setAttribute("role", "option"); li.setAttribute("aria-selected", "false");
        var a = document.createElement("span"); a.className = "wb-pick-main"; a.textContent = p.sku + " · " + p.name;
        var b = document.createElement("span"); b.className = "wb-pick-sub"; b.textContent = describe(p);
        li.appendChild(a); li.appendChild(b);
        if (p.warn) { var w = document.createElement("span"); w.className = "wb-pick-warn"; w.textContent = "Needs approval at this price"; li.appendChild(w); }
        li.addEventListener("mousedown", function (e) { e.preventDefault(); choose(i); });
        list.appendChild(li);
      });
      list.hidden = false; input.setAttribute("aria-expanded", "true"); note.textContent = items.length + (items.length === 1 ? " match" : " matches");
    }
    function fetchNow() {
      var q = input.value.trim(), mine = ++seq;
      if (!q) { items = []; draw(); return; }
      fetch(url + (url.indexOf("?") < 0 ? "?" : "&") + "q=" + encodeURIComponent(q), { credentials: "same-origin", headers: { "Accept": "application/json" } })
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(function (data) { if (mine !== seq) return; items = Array.isArray(data) ? data : []; draw(); })
        .catch(function () { if (mine === seq) note.textContent = "The product list could not be reached. Type the exact code instead."; });
    }
    input.addEventListener("input", function () { hidden.value = ""; note.classList.remove("is-warn"); clearTimeout(timer); timer = setTimeout(fetchNow, 180); });
    input.addEventListener("keydown", function (e) {
      if (list.hidden && (e.key === "ArrowDown") && items.length) { draw(); mark(0); e.preventDefault(); return; }
      if (list.hidden) return;
      if (e.key === "ArrowDown") { mark(Math.min(items.length - 1, active + 1)); e.preventDefault(); }
      else if (e.key === "ArrowUp") { mark(Math.max(0, active - 1)); e.preventDefault(); }
      else if (e.key === "Enter") { if (active >= 0) { choose(active); e.preventDefault(); } else if (items.length === 1) { choose(0); e.preventDefault(); } }
      else if (e.key === "Escape") { close(); e.preventDefault(); }
    });
    input.addEventListener("blur", function () { setTimeout(close, 120); });
  }

  document.querySelectorAll("[data-wb-pick]").forEach(picker);
})();

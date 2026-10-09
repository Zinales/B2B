/**
 * B2BGro workspace script (1.5.0). Only what the server cannot do on its own; every form still
 * works without it. Plain JavaScript, no library.
 *
 *  1. Product search: a field marked data-wb-pick becomes a list of matches as you type (code, name
 *     or barcode), each with this customer's price, where it comes from and what is in stock. The
 *     arrow keys move, Enter chooses, Escape closes. With data-wb-pick-into the choice is added to
 *     a textarea of lines (purchase orders) instead.
 *  2. Signature (1.7.0): a box marked data-wb-sign takes a finger or mouse signature and puts it in
 *     the form as a small PNG. Without it the name alone is recorded.
 *  3. Keys (1.7.0): "/" goes to the search box, "n" to the screen's main action, Escape closes a
 *     menu or the confirm sheet. Never while typing in a field.
 *  4. The confirm sheet (1.7.0): a form that asks "are you sure?" (data-wb-confirm, data-wb-danger,
 *     data-wb-reason) asks in the page, in words, with the button named for what it does, instead of
 *     the browser's box. The older handler stands down when this one is here.
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

  /* 2. signature ---------------------------------------------------------------- */
  document.querySelectorAll("[data-wb-sign]").forEach(function (box) {
    var c = box.querySelector("canvas"), out = box.querySelector("input[type=hidden]"), ctx = c.getContext("2d"), drawing = false, inked = false;
    ctx.lineWidth = 2.5; ctx.lineCap = "round"; ctx.lineJoin = "round"; ctx.strokeStyle = "#0B1F3A";
    function at(e) { var r = c.getBoundingClientRect(); return [(e.clientX - r.left) * c.width / r.width, (e.clientY - r.top) * c.height / r.height]; }
    c.addEventListener("pointerdown", function (e) { drawing = true; c.setPointerCapture(e.pointerId); var p = at(e); ctx.beginPath(); ctx.moveTo(p[0], p[1]); e.preventDefault(); });
    c.addEventListener("pointermove", function (e) { if (!drawing) return; var p = at(e); ctx.lineTo(p[0], p[1]); ctx.stroke(); inked = true; e.preventDefault(); });
    function end() { if (!drawing) return; drawing = false; if (inked) out.value = c.toDataURL("image/png"); }
    c.addEventListener("pointerup", end); c.addEventListener("pointercancel", end); c.addEventListener("pointerleave", end);
    box.querySelector("[data-wb-sign-clear]").addEventListener("click", function () { ctx.clearRect(0, 0, c.width, c.height); inked = false; out.value = ""; });
  });

  /* 4. the confirm sheet ---------------------------------------------------------- */
  var sheet = null;
  function ask(opts, done) {
    if (!sheet) {
      sheet = document.createElement("dialog"); sheet.className = "wb-sheet";
      sheet.innerHTML = '<form method="dialog"><p class="wb-sheet-q"></p><p class="wb-sheet-danger" hidden>This cannot be undone.</p><label class="wb-field wb-sheet-why" hidden><span></span><textarea rows="3"></textarea></label><div class="wb-sheet-acts"><button value="no" class="wb-btn wb-btn-ghost">Cancel</button><button value="yes" class="wb-btn wb-sheet-go"></button></div></form>';
      document.body.appendChild(sheet);
    }
    sheet.querySelector(".wb-sheet-q").textContent = opts.question;
    sheet.querySelector(".wb-sheet-danger").hidden = !opts.danger;
    var why = sheet.querySelector(".wb-sheet-why"), ta = why.querySelector("textarea");
    why.hidden = !opts.reason; why.querySelector("span").textContent = opts.reason || ""; ta.value = ""; ta.required = !!opts.reason;
    var go = sheet.querySelector(".wb-sheet-go"); go.textContent = opts.go; go.classList.toggle("wb-btn-danger", !!opts.danger);
    sheet.onclose = function () { if (sheet.returnValue === "yes") done(ta.value.trim()); };
    sheet.showModal(); (opts.reason ? ta : go).focus();
  }
  window.wbSheet = true;   // the older browser-box handler stands down
  document.addEventListener("submit", function (e) {
    var f = e.target; if (f.dataset.wbAsked) { delete f.dataset.wbAsked; return; }
    var s = e.submitter, q = f.getAttribute("data-wb-confirm") || (s && s.getAttribute("data-wb-confirm"));
    var danger = !!f.getAttribute("data-wb-danger"), reason = f.getAttribute("data-wb-reason");
    if (!q && !danger && !reason) return;
    e.preventDefault(); e.stopImmediatePropagation();
    var words = (s && (s.textContent || s.value || "").trim()) || "Go ahead";
    ask({ question: q || (reason ? "Before it is done:" : "Are you sure?"), danger: danger, reason: reason, go: words }, function (why) {
      if (reason) { if (!why) return; var r = f.querySelector("input[name=wb_reason]"); if (r) r.value = why; }
      f.dataset.wbAsked = "1";
      if (f.requestSubmit) f.requestSubmit(s || undefined); else f.submit();
    });
  }, true);

  /* 3. keys ----------------------------------------------------------------------- */
  document.addEventListener("keydown", function (e) {
    var t = e.target, typing = t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
    if (e.key === "Escape") { document.querySelectorAll(".wb-kebab.is-open").forEach(function (k) { k.classList.remove("is-open"); var b = k.querySelector(".wb-kebab-btn"); if (b) { b.setAttribute("aria-expanded", "false"); b.focus(); } }); return; }
    if (typing || e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.key === "/") { var q = document.querySelector(".wb-listbar-q input, .wb-pick input[type=text]"); if (q) { e.preventDefault(); q.focus(); q.select(); } }
    else if (e.key === "n") { var a = document.querySelector(".wb-head-acts a, .wb-head-acts button"); if (a) { e.preventDefault(); a.click(); } }
  });
})();

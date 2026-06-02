/* ARGOS — task detail building blocks */
const { useState:useStateP, useEffect:useEffectP, useRef:useRefP } = React;

/* ---------- Phase rail (informational progress, single level) ---------- */
const RAIL_ICON = {Entwurf:Icon.doc, Konzept:Icon.bulb, Implement:Icon.code, Push:Icon.push, Review:Icon.feedback};
function PhaseRail({task}) {
  const rail = task.rail || [];
  const activeIdx = rail.findIndex(s=>s==="active"||s==="wait"||s==="fail");
  const cur = rail[activeIdx];
  const note = {active:"läuft gerade", wait:"wartet auf dein Feedback", fail:"fehlgeschlagen"}[cur] || "abgeschlossen";
  return React.createElement("div",{className:"rail"},
    RAIL_PHASES.map((p,i)=>{
      const st = rail[i] || "todo";
      const Ic = RAIL_ICON[p];
      return React.createElement(React.Fragment,{key:p},
        i>0 && React.createElement("div",{className:`rail-line ${rail[i-1]==="done"?"done":""}`}),
        React.createElement("div",{className:`rail-node st-${st} ${task.status==="running"&&st==="active"?"pulse":""}`, title:p},
          React.createElement("div",{className:"rail-dot"},
            st==="done" ? React.createElement(Icon.check) :
            st==="fail" ? React.createElement(Icon.x) :
            st==="wait" ? React.createElement(Icon.hand) :
            React.createElement(Ic)),
          React.createElement("span",{className:"rail-lbl"}, p)));
    }),
    React.createElement("div",{className:"rail-note"},
      React.createElement("span",{className:"rail-cur"}, RAIL_PHASES[activeIdx>=0?activeIdx:RAIL_PHASES.length-1]),
      React.createElement("span",{className:"rail-sub"}, note))
  );
}

/* ---------- Action menu (kebab) ---------- */
function ActionMenu({items}) {
  const [open, setOpen] = useStateP(false);
  const ref = useRefP(null);
  useEffectP(()=>{
    const h = e=>{ if(ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener("mousedown", h); return ()=>document.removeEventListener("mousedown", h);
  },[]);
  return React.createElement("div",{className:"menu-wrap", ref},
    React.createElement("button",{className:"iconbtn", onClick:()=>setOpen(o=>!o), title:"Weitere Aktionen"},
      React.createElement(Icon.dots)),
    open && React.createElement("div",{className:"menu fade-in"},
      items.map((it,i)=> it.divider
        ? React.createElement("div",{key:i, className:"menu-div"})
        : React.createElement("button",{key:i, className:`menu-item ${it.danger?"danger":""}`, onClick:()=>{setOpen(false); it.onClick&&it.onClick();}},
            it.icon && React.createElement(it.icon), it.label)))
  );
}

function primaryAction(task) {
  const rail = task.rail || [];
  const failI = rail.indexOf("fail");
  if(failI>=0) return {label:"Erneut versuchen", icon:Icon.refresh, variant:"primary"};
  const i = rail.findIndex(s=>s==="active"||s==="wait");
  const map = {
    0:{label:"Konzept starten", icon:Icon.bulb, variant:"primary"},
    1:{label:"Implementieren", icon:Icon.code, variant:"primary"},
    2:{label:"Push & PR", icon:Icon.push, variant:"primary"},
    3:{label:"Push & PR", icon:Icon.push, variant:"primary"},
    4:{label:"Abschließen", icon:Icon.checkCircle, variant:"success"},
  };
  return map[i] || {label:"Abschließen", icon:Icon.checkCircle, variant:"success"};
}

/* ---------- Meta variants ---------- */
function MetaRow({k, v, mono, link, ext}) {
  return React.createElement("div",{className:"kv"},
    React.createElement("span",{className:"k"}, k),
    React.createElement("span",{className:`v ${mono?"mono":""} ${link?"link":""}`},
      v, ext && React.createElement(Icon.external,{style:{width:13,height:13,marginLeft:5,verticalAlign:"-2px"}})));
}

/* A — compact horizontal strip + expandable details */
function MetaStrip({task}) {
  const [more, setMore] = useStateP(false);
  const item = (label, val, cls)=>React.createElement("div",{className:"ms-item"},
    React.createElement("span",{className:"ms-k"}, label),
    React.createElement("span",{className:`ms-v ${cls||""}`}, val));
  return React.createElement("div",{className:"meta-strip"},
    React.createElement("div",{className:"ms-row"},
      item("Repository", task.repo, "mono"),
      item("Branch", task.branch, "mono link"),
      task.pr && item("PR", task.pr, "mono link"),
      item("Agent", task.agent),
      item("Stack", task.stack, "mono"),
      item("Kosten", task.cost+" · "+task.tokens+" tok", "mono"),
      React.createElement("button",{className:"ms-more", onClick:()=>setMore(m=>!m)},
        React.createElement(Icon.chevDown,{style:{width:14,height:14,transform:more?"rotate(180deg)":"none",transition:".2s"}}),
        more?"weniger":"Details")),
    more && React.createElement("div",{className:"ms-row ms-extra fade-in"},
      item("Base Branch", task.baseBranch, "mono"),
      item("Tokens", task.tokens, "mono"),
      item("Erstellt", task.created, "mono")))
}

/* B — grouped sidebar card (right column) */
function MetaSidebar({task}) {
  const G = ({label, children})=>React.createElement("div",{className:"meta-grp"},
    React.createElement("div",{className:"meta-grp-lbl"}, label), children);
  return React.createElement(Card,{className:"card-pad meta-side"},
    React.createElement(G,{label:"Quelle"},
      React.createElement(MetaRow,{k:"Repository", v:task.repo, mono:true}),
      React.createElement(MetaRow,{k:"Base Branch", v:task.baseBranch, mono:true}),
      React.createElement(MetaRow,{k:"Branch", v:task.branch, mono:true, link:true}),
      task.pr && React.createElement(MetaRow,{k:"Pull Request", v:task.pr, link:true, ext:true})),
    React.createElement(G,{label:"Ausführung"},
      React.createElement(MetaRow,{k:"Agent", v:task.agent}),
      React.createElement(MetaRow,{k:"Stack", v:task.stack, mono:true})),
    React.createElement(G,{label:"Verbrauch"},
      React.createElement(MetaRow,{k:"Kosten", v:task.cost, mono:true}),
      React.createElement(MetaRow,{k:"Tokens", v:task.tokens, mono:true}),
      React.createElement(MetaRow,{k:"Erstellt", v:task.created, mono:true})))
}

/* C — clean 2-column list (used inside Übersicht tab) */
function MetaList({task}) {
  return React.createElement("div",{className:"meta-list"},
    React.createElement(MetaRow,{k:"Repository", v:task.repo, mono:true}),
    React.createElement(MetaRow,{k:"Base Branch", v:task.baseBranch, mono:true}),
    React.createElement(MetaRow,{k:"Branch", v:task.branch, mono:true, link:true}),
    task.pr && React.createElement(MetaRow,{k:"Pull Request", v:task.pr, link:true, ext:true}),
    React.createElement(MetaRow,{k:"Agent", v:task.agent}),
    React.createElement(MetaRow,{k:"Stack", v:task.stack, mono:true}),
    React.createElement(MetaRow,{k:"Kosten", v:task.cost, mono:true}),
    React.createElement(MetaRow,{k:"Tokens", v:task.tokens, mono:true}),
    React.createElement(MetaRow,{k:"Erstellt", v:task.created, mono:true}))
}

/* ---------- Respond composer (docked) ---------- */
function RespondComposer({task, dock}) {
  const [val, setVal] = useStateP("");
  const waiting = task.status==="waiting";
  return React.createElement("div",{className:`respond ${dock?"respond-dock":""} ${waiting?"is-waiting":""}`},
    React.createElement("div",{className:"respond-inner"},
      waiting && React.createElement("div",{className:"respond-flag"},
        React.createElement(Icon.hand), "Der Agent wartet auf dein Feedback"),
      React.createElement("div",{className:"respond-body"},
        React.createElement(Avatar,{initials:"AA"}),
        React.createElement("textarea",{className:"respond-ta", rows:dock?1:2, value:val,
          onChange:e=>setVal(e.target.value),
          placeholder:waiting?"Antworte dem Agenten oder fordere Änderungen an…":"Nachricht / Feedback an den Agenten…"}),
        React.createElement(Btn,{variant:"primary", icon:Icon.send, disabled:!val.trim()}, "Senden")),
      React.createElement("div",{className:"respond-quick"},
        React.createElement("button",{className:"chip", onClick:()=>setVal("Bitte ergänze einen Test für den 404-Fall.")}, "Änderungen anfordern"),
        React.createElement("button",{className:"chip", onClick:()=>setVal("Sieht gut aus — bitte mergen.")}, "Approve & Merge"),
        React.createElement("button",{className:"chip"}, "Frage stellen")))
  );
}

/* ---------- Concept ---------- */
function Concept() {
  const [mode, setMode] = useStateP("inhalt");
  return React.createElement("div",{style:{maxWidth:760}},
    React.createElement("div",{style:{display:"flex", gap:8, marginBottom:20}},
      React.createElement("button",{className:`chip ${mode==="inhalt"?"on":""}`, onClick:()=>setMode("inhalt")}, React.createElement(Icon.bulb), "Inhaltlich"),
      React.createElement("button",{className:`chip ${mode==="tech"?"on":""}`, onClick:()=>setMode("tech")}, React.createElement(Icon.code), "Technisch")),
    React.createElement("h2",{style:{fontSize:"var(--t-h1)", margin:"0 0 16px"}},"Basis Laravel-Installation"),
    React.createElement("h3",{style:{fontSize:"var(--t-h2)", margin:"0 0 8px"}},"Was wurde umgesetzt"),
    React.createElement("p",{style:{color:"var(--text-2)", margin:"0 0 20px"}},
      "Es wurde eine vollständige, lauffähige Basis-Webanwendung eingerichtet. Diese dient als solide Ausgangslage für alle künftigen Entwicklungsaufgaben in diesem Repository."),
    React.createElement("h3",{style:{fontSize:"var(--t-h2)", margin:"0 0 8px"}},"Was sich konkret verändert hat"),
    React.createElement("ul",{style:{color:"var(--text-2)", lineHeight:1.9, margin:"0 0 20px", paddingLeft:20}},
      React.createElement("li",null,"Eine Startseite (Laravel-Willkommensseite), im Browser aufrufbar."),
      React.createElement("li",null,"Eine SQLite-Datenbank, automatisch mit Standard-Tabellen eingerichtet."),
      React.createElement("li",null,"Frontend-Build-System (Vite), das optimierte Dateien erzeugt."),
      React.createElement("li",null,"Zwei Smoke-Tests für die Grundfunktionen der Anwendung."),
      React.createElement("li",null,"Setup-Anleitung im README für neue Entwickler.")),
    React.createElement("div",{className:"callout callout-warn"},
      React.createElement(Icon.warn),
      React.createElement("div",null,
        React.createElement("b",null,"Abweichung vom Plan. "),
        "Das Konzept nannte Pest als Test-Framework. Laravel 13 nutzt jedoch PHPUnit 12 direkt — Pest ist nicht mehr im Default-Skeleton. Die Tests laufen mit PHPUnit, funktional gleichwertig."))
  );
}

/* ---------- Terminal ---------- */
function Terminal({lines, title}) {
  const [shown, setShown] = useStateP(lines.length);
  const [streaming, setStreaming] = useStateP(false);
  const bodyRef = useRefP(null);
  useEffectP(()=>{
    if(!streaming) return;
    if(shown >= lines.length){ setStreaming(false); return; }
    const id = setTimeout(()=>{ setShown(s=>s+1); if(bodyRef.current) bodyRef.current.scrollTop = bodyRef.current.scrollHeight; }, 260);
    return ()=>clearTimeout(id);
  },[streaming, shown, lines.length]);
  return React.createElement("div",{className:"term"},
    React.createElement("div",{className:"term-head"},
      React.createElement("div",{className:"term-dots"},
        React.createElement("i",{style:{background:"#ff5f57"}}),
        React.createElement("i",{style:{background:"#febc2e"}}),
        React.createElement("i",{style:{background:"#28c840"}})),
      React.createElement("span",{className:"term-title"}, title || "worker · feat/Basis-Laravel"),
      React.createElement("div",{style:{marginLeft:"auto", display:"flex", gap:8}},
        React.createElement("button",{className:"btn btn-ghost btn-sm", style:{color:"#9aa3b2"}, onClick:()=>{setShown(0); setStreaming(true);}},
          React.createElement(Icon.refresh), "Replay"))),
    React.createElement("div",{className:"term-body", ref:bodyRef},
      lines.slice(0,shown).map((l,i)=>React.createElement("div",{key:i, className:"term-line"},
        React.createElement("span",{className:"ln"}, i+1),
        React.createElement("span",{className:"tt"}, l.t),
        React.createElement("span",{className:l.k}, l.x))),
      streaming && React.createElement("div",{className:"term-line"},
        React.createElement("span",{className:"ln"}, shown+1),
        React.createElement("span",{className:"cursor"})))
  );
}

/* ---------- Diff ---------- */
function Diff({files}) {
  return React.createElement("div",{style:{display:"grid", gap:16}},
    files.map((f,fi)=>React.createElement("div",{key:fi, className:"diff"},
      React.createElement("div",{className:"diff-file"},
        React.createElement(Icon.doc,{style:{width:14,height:14,color:"var(--muted)",flex:"none"}}),
        React.createElement("span",{className:"path"}, f.file),
        React.createElement("span",{style:{display:"flex", gap:10}},
          React.createElement("span",{className:"stat-add"}, "+"+f.add),
          React.createElement("span",{className:"stat-del"}, "−"+f.del))),
      React.createElement("div",{className:"diff-body"},
        f.rows.map((r,ri)=>React.createElement("div",{key:ri, className:`diff-row ${r.t}`},
          React.createElement("div",{className:"gut"},
            React.createElement("span",null, r.n1),
            React.createElement("span",null, r.n2)),
          React.createElement("div",{className:"code"},
            (r.t==="add"?"+ ":r.t==="del"?"− ":"  ")+r.c))))
    ))
  );
}

Object.assign(window, { PhaseRail, ActionMenu, primaryAction, MetaStrip, MetaSidebar, MetaList, MetaRow, RespondComposer, Concept, Terminal, Diff });

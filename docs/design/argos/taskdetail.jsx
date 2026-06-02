/* ARGOS — task detail: layouts + container */

function LiveDemo({task}) {
  if(!(task.demo && task.demo.live)) return null;
  return React.createElement(Card,{className:"card-pad", style:{marginBottom:16}},
    React.createElement("div",{style:{display:"flex", alignItems:"center", gap:12, flexWrap:"wrap"}},
      React.createElement(Icon.globe,{style:{width:20,height:20,color:"var(--muted)",flex:"none"}}),
      React.createElement("span",{style:{fontWeight:600, whiteSpace:"nowrap"}},"Live-Demo"),
      React.createElement(StatusBadge,{status:"running", label:"Live"}),
      React.createElement("a",{href:"#", className:"mono", style:{marginLeft:"auto", color:"var(--accent-text)", fontSize:"var(--t-xs)", display:"flex", alignItems:"center", gap:5}},
        task.demo.url, React.createElement(Icon.external,{style:{width:13,height:13}})),
      React.createElement("span",{style:{fontSize:"var(--t-xs)", color:"var(--faint)"}}, "läuft ab ", task.demo.expires)))
}

function SummaryCard({task}) {
  return React.createElement(Card,{className:"card-pad"},
    React.createElement("div",{style:{display:"flex", gap:12, alignItems:"flex-start"}},
      React.createElement(Icon.doc,{style:{width:20,height:20,color:"var(--muted)", flex:"none", marginTop:2}}),
      React.createElement("div",null,
        React.createElement("div",{style:{fontWeight:600, fontSize:"var(--t-h3)", marginBottom:4}}, "Aufgabe"),
        React.createElement("p",{style:{margin:0, color:"var(--text-2)", fontSize:"var(--t-sm)"}}, task.desc))))
}

/* ---- Activity feed ---- */
function ActivityFeed({task, expandable}) {
  const [openDiff, setOpenDiff] = React.useState(false);
  const [openLogs, setOpenLogs] = React.useState(false);
  const [openConcept, setOpenConcept] = React.useState(false);
  return React.createElement("div",{className:"feed"},
    ACTIVITY.map((a,i)=>{
      const Ic = RAIL_ICON[a.phase] || Icon.doc;
      return React.createElement("div",{key:i, className:"feed-item"},
        React.createElement("div",{className:`feed-node st-${a.status}`}, React.createElement(Ic)),
        React.createElement("div",{className:"feed-card card"},
          React.createElement("div",{className:"feed-head"},
            React.createElement("span",{className:"feed-title"}, a.title),
            React.createElement("span",{className:"feed-meta"},
              a.cost && React.createElement("span",{className:"mono", style:{color:"var(--ok-600)"}}, a.cost),
              React.createElement("span",{className:"mono"}, a.time),
              React.createElement("span",{className:"feed-who"}, a.who))),
          React.createElement("p",{className:"feed-body"}, a.body),
          expandable && a.phase==="Konzept" && React.createElement("div",{className:"feed-expand"},
            React.createElement("div",{className:"feed-actions"},
              React.createElement("button",{className:`link-btn ${openConcept?"on":""}`, onClick:()=>setOpenConcept(o=>!o)},
                React.createElement(Icon.bulb), openConcept?"Konzept ausblenden":"Konzept ansehen")),
            openConcept && React.createElement("div",{className:"feed-detail"}, React.createElement(Concept))),
          expandable && a.phase==="Implement" && React.createElement("div",{className:"feed-expand"},
            React.createElement("div",{className:"feed-actions"},
              React.createElement("button",{className:`link-btn ${openDiff?"on":""}`, onClick:()=>{setOpenDiff(o=>!o); setOpenLogs(false);}},
                React.createElement(Icon.branch), "Diff · 3 Dateien"),
              React.createElement("button",{className:`link-btn ${openLogs?"on":""}`, onClick:()=>{setOpenLogs(o=>!o); setOpenDiff(false);}},
                React.createElement(Icon.activity), "Logs · 133")),
            openDiff && React.createElement("div",{className:"feed-detail"}, React.createElement(Diff,{files:DIFF})),
            openLogs && React.createElement("div",{className:"feed-detail"}, React.createElement(Terminal,{lines:LOG_LINES})))));
    })
  );
}

/* ---- Workspace layout (single-level tabs) ---- */
function WorkspaceLayout({task, metaVariant}) {
  const [tab, setTab] = React.useState("uebersicht");
  const tabs = [
    {id:"uebersicht", label:"Übersicht", icon:Icon.home},
    {id:"konzept", label:"Konzept", icon:Icon.bulb},
    {id:"diff", label:"Diff", icon:Icon.branch},
    {id:"logs", label:"Logs", icon:Icon.activity, pill:"133"},
    {id:"aktiv", label:"Aktivität", icon:Icon.clock},
  ];
  const overview =
    metaVariant==="sidebar"
      ? React.createElement("div",{className:"grid-2"},
          React.createElement("div",null, React.createElement(SummaryCard,{task}), React.createElement("div",{style:{height:16}}), React.createElement(LiveDemo,{task})),
          React.createElement(MetaSidebar,{task}))
      : React.createElement("div",null,
          React.createElement(SummaryCard,{task}),
          React.createElement("div",{style:{height:16}}),
          React.createElement(LiveDemo,{task}),
          metaVariant==="tab" && React.createElement(Card,{className:"card-pad", style:{marginTop:0}},
            React.createElement("h3",{style:{margin:"0 0 6px", fontSize:"var(--t-h3)"}},"Details"),
            React.createElement(MetaList,{task})));
  return React.createElement("div",null,
    React.createElement("div",{className:"tabs", style:{marginBottom:22}},
      tabs.map(tb=>React.createElement("button",{key:tb.id, className:`tab ${tab===tb.id?"on":""}`, onClick:()=>setTab(tb.id)},
        React.createElement(tb.icon), tb.label, tb.pill && React.createElement("span",{className:"pill"}, tb.pill)))),
    React.createElement("div",{className:"fade-in", key:tab},
      tab==="uebersicht" && overview,
      tab==="konzept" && React.createElement(Card,{className:"card-pad"}, React.createElement(Concept)),
      tab==="diff" && React.createElement(Diff,{files:DIFF}),
      tab==="logs" && React.createElement(Terminal,{lines:LOG_LINES}),
      tab==="aktiv" && React.createElement(ActivityFeed,{task, expandable:false}))
  );
}

/* ---- Thread layout (chronological feed) ---- */
function ThreadLayout({task}) {
  return React.createElement("div",null,
    React.createElement(LiveDemo,{task}),
    React.createElement(ActivityFeed,{task, expandable:true}))
}

/* ---- container ---- */
function TaskDetail({task, go}) {
  const t = task || TASKS[0];

  const prim = primaryAction(t);
  const menuItems = [
    {label:"Demo neu aufbauen", icon:Icon.refresh},
    {label:"Logs herunterladen", icon:Icon.download},
    {label:"Konzept aktualisieren", icon:Icon.bulb},
    t.pr && {label:"Pull Request öffnen", icon:Icon.external},
    {divider:true},
    {label:"Task löschen", icon:Icon.trash, danger:true},
  ].filter(Boolean);

  return React.createElement("div",{className:"content fade-in task-detail"},
    /* header */
    React.createElement("div",{className:"td-head"},
      React.createElement("div",{className:"td-head-l"},
        React.createElement("div",{className:"crumbs", style:{fontSize:"var(--t-sm)", color:"var(--muted)", display:"flex", gap:8, alignItems:"center", marginBottom:6, cursor:"pointer"}, onClick:()=>go("tasks")},
          React.createElement("span",null,"Tasks"), React.createElement(Icon.chevRight,{style:{width:13,height:13,opacity:.5}}), React.createElement("span",null,"Ansehen")),
        React.createElement("div",{style:{display:"flex", alignItems:"center", gap:12, flexWrap:"wrap"}},
          React.createElement("h1",{className:"page-title", style:{marginBottom:0}}, t.name),
          React.createElement(StatusBadge,{status:t.workflowStatus, label:t.workflow}))),
      React.createElement("div",{className:"td-actions"},
        React.createElement(Btn,{variant:prim.variant, icon:prim.icon}, prim.label),
        React.createElement(ActionMenu,{items:menuItems}))),

    /* phase rail */
    React.createElement(PhaseRail,{task:t}),

    /* meta strip — everything important visible, rest expands */
    React.createElement(MetaStrip,{task:t}),

    /* thread body */
    React.createElement("div",{style:{marginTop:20}},
      React.createElement(ThreadLayout,{task:t})),

    /* docked respond */
    React.createElement(RespondComposer,{task:t, dock:true})
  );
}

Object.assign(window, { TaskDetail, ThreadLayout, ActivityFeed, LiveDemo, SummaryCard });

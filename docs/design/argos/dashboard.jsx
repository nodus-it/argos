/* ARGOS — Dashboard (control room) */

function Stat({label, icon, num, meta, metaIcon, metaCls, live}) {
  return React.createElement("div",{className:`stat ${live?"is-live":""}`},
    React.createElement("div",{className:"accent-bar"}),
    React.createElement("div",{className:"lbl"}, icon && React.createElement(icon), label),
    React.createElement("div",{className:"num"}, num),
    React.createElement("div",{className:"meta"},
      metaIcon && React.createElement(metaIcon,{className:metaCls}),
      React.createElement("span",{className:metaCls}, meta))
  );
}

function TaskTable({tasks, go, columns}) {
  return React.createElement("table",{className:"tbl"},
    React.createElement("thead",null, React.createElement("tr",null,
      columns.map(c=>React.createElement("th",{key:c}, c)))),
    React.createElement("tbody",null,
      tasks.map(t=>React.createElement("tr",{key:t.id, onClick:()=>go("taskdetail", t.id)},
        React.createElement("td",null, React.createElement("span",{className:"name"}, t.name)),
        columns.includes("Projekt") && React.createElement("td",{className:"dim"}, t.project),
        React.createElement("td",{className:"dim mono-cell"}, t.source),
        React.createElement("td",null, React.createElement(StatusBadge,{status:t.workflowStatus, label:t.workflow})),
        React.createElement("td",null, React.createElement(PhaseChip,{phase:t.phase, on:t.status==="running"})),
        React.createElement("td",null, React.createElement("span",{className:"chip"}, t.agent)),
        React.createElement("td",{className:"dim"}, t.activity)
      ))
    )
  );
}

function Dashboard({go}) {
  const running = TASKS.filter(t=>t.status==="running").length;
  const waiting = TASKS.filter(t=>t.status==="waiting").length;
  return React.createElement("div",{className:"content fade-in"},
    React.createElement("h1",{className:"page-title"},"Dashboard"),
    React.createElement("p",{className:"page-sub"},"Kontrollraum — Status aller Agenten auf einen Blick. Aktualisiert alle 5 s."),
    /* stat row */
    React.createElement("div",{className:"stats"},
      React.createElement(Stat,{label:"Laufende Worker", icon:Icon.chip, num:running, live:running>0,
        meta:running>0?"Agenten aktiv":"Keine aktiven Worker", metaIcon:running>0?Icon.bolt:Icon.chip, metaCls:running>0?"":""}),
      React.createElement(Stat,{label:"In Bearbeitung", icon:Icon.refresh, num:running,
        meta:`${running} Tasks laufen`, metaIcon:Icon.refresh}),
      React.createElement(Stat,{label:"Wartet auf dich", icon:Icon.hand, num:waiting, live:waiting>0,
        meta:waiting>0?"Feedback benötigt":"Nichts zu tun", metaIcon:waiting>0?Icon.warn:Icon.check, metaCls:waiting>0?"wn":"ok"}),
      React.createElement(Stat,{label:"Worker-Updates", icon:Icon.cube, num:0,
        meta:"Alles aktuell", metaIcon:Icon.checkCircle, metaCls:"ok"})
    ),
    /* tasks table */
    React.createElement(Card,null,
      React.createElement(CardHead,{icon:Icon.tasks, title:"Aktuelle Tasks", right:[
        React.createElement("div",{key:"s", style:{position:"relative"}},
          React.createElement(Icon.search,{style:{width:16,height:16,position:"absolute",left:10,top:9,color:"var(--faint)"}}),
          React.createElement("input",{placeholder:"Suche…", style:{
            font:"inherit", fontSize:"var(--t-sm)", padding:"7px 10px 7px 32px", width:200,
            background:"var(--surface-2)", border:"1px solid var(--border)", borderRadius:"var(--radius)",
            color:"var(--text)", outline:"none"}})),
        React.createElement("button",{key:"f", className:"iconbtn"}, React.createElement(Icon.filter)),
        React.createElement("button",{key:"c", className:"iconbtn"}, React.createElement(Icon.columns)),
      ]}),
      React.createElement("div",{style:{overflowX:"auto"}},
        React.createElement(TaskTable,{tasks:TASKS, go,
          columns:["Name","Projekt","Quelle","Workflow","Phase","Agent","Letzte Aktivität"]}))
    )
  );
}

Object.assign(window, { Dashboard, TaskTable, Stat });

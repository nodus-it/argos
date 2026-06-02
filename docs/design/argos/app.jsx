/* ARGOS — app root */
function App() {
  const [route, setRoute] = useState(()=>localStorage.getItem("argos.route")||"overview");
  const [taskId, setTaskId] = useState(()=>localStorage.getItem("argos.task")||"basis-laravel");
  const [dir, setDir] = useState(()=>localStorage.getItem("argos.dir")||"2");
  const [theme, setTheme] = useState(()=>localStorage.getItem("argos.theme")||"light");
  const [logo, setLogo] = useState(()=>localStorage.getItem("argos.logo")||"lens");
  useEffect(()=>{ localStorage.setItem("argos.logo", logo); },[logo]);

  useEffect(()=>{
    document.documentElement.setAttribute("data-dir", dir);
    document.documentElement.setAttribute("data-theme", theme);
    localStorage.setItem("argos.dir", dir);
    localStorage.setItem("argos.theme", theme);
  },[dir,theme]);
  useEffect(()=>{ localStorage.setItem("argos.route", route); localStorage.setItem("argos.task", taskId); },[route,taskId]);

  const go = (r, id)=>{ setRoute(r); if(id) setTaskId(id); window.scrollTo(0,0); };
  const task = TASKS.find(t=>t.id===taskId) || TASKS[0];

  const crumbsFor = {
    overview:[{label:"Übersicht", active:true}],
    dashboard:[{label:"Dashboard", active:true}],
    tasks:[{label:"Tasks", active:true}],
    taskdetail:[{label:"Tasks"},{label:task.name, active:true}],
  };
  const crumbs = crumbsFor[route] || [{label:"Dashboard", active:true}];

  let view;
  if(route==="overview") view = React.createElement(Overview,{dir, logo, setLogo});
  else if(route==="dashboard") view = React.createElement(Dashboard,{go});
  else if(route==="taskdetail") view = React.createElement(TaskDetail,{task, go});
  else if(route==="tasks") view = React.createElement(TasksList,{go});
  else view = React.createElement(Placeholder,{route});

  return React.createElement("div",{className:"app"},
    React.createElement(Sidebar,{route, go, logo}),
    React.createElement("div",{className:"main"},
      React.createElement(Topbar,{crumbs, dir, setDir, theme, setTheme}),
      /* overview entry chip */
      route!=="overview" && React.createElement("div",{style:{padding:"0 28px", marginTop:-1}}),
      view,
      React.createElement(Footer)
    )
  );
}

function TasksList({go}) {
  const [seg, setSeg] = useState("aktuell");
  return React.createElement("div",{className:"content fade-in"},
    React.createElement("h1",{className:"page-title"},"Tasks"),
    React.createElement("p",{className:"page-sub"},"Alle Aufgaben über deine verbundenen Repositories hinweg."),
    React.createElement("div",{style:{display:"flex", justifyContent:"center", marginBottom:18}},
      React.createElement(Seg,{value:seg, onChange:setSeg, options:[
        {value:"aktuell", label:"Aktuell"},{value:"wartend", label:"Wartend"},
        {value:"fertig", label:"Abgeschlossen"},{value:"alle", label:"Alle"}]})),
    React.createElement(Card,null,
      React.createElement(CardHead,{icon:Icon.tasks, title:"Tasks", right:[
        React.createElement("button",{key:"f", className:"iconbtn"}, React.createElement(Icon.filter)),
        React.createElement("button",{key:"c", className:"iconbtn"}, React.createElement(Icon.columns))]}),
      React.createElement("div",{style:{overflowX:"auto"}},
        React.createElement(TaskTable,{tasks:TASKS, go,
          columns:["Name","Projekt","Quelle","Workflow","Phase","Agent","Letzte Aktivität"]})))
  );
}

function Placeholder({route}) {
  const labels = {stacks:"Stacks","agent-credentials":"Agent-Credentials","image-builds":"Image-Builds",
    projekte:"Projekte",accounts:"Verknüpfte Accounts","access-tokens":"Access-Tokens",
    "oauth-apps":"OAuth-Apps","api-clients":"API-Clients",einstellungen:"Einstellungen"};
  return React.createElement("div",{className:"content fade-in"},
    React.createElement("h1",{className:"page-title"}, labels[route]||route),
    React.createElement("p",{className:"page-sub"},"Tier-3-Konfiguration — bleibt nah an den Filament-Defaults, nutzt aber dieselben Tokens & Komponenten."),
    React.createElement(Card,{className:"card-pad"},
      React.createElement("div",{style:{textAlign:"center", padding:"40px 20px", color:"var(--muted)"}},
        React.createElement(Icon.gear,{style:{width:32,height:32, margin:"0 auto 12px", display:"block", color:"var(--faint)"}}),
        React.createElement("p",{style:{margin:0, fontSize:"var(--t-sm)"}},
          "CRUD-Screen — in dieser Exploration nicht voll ausgearbeitet. ",
          "Fokus liegt auf Dashboard & Task-Detail."))));
}

function Footer() {
  return React.createElement("footer",{style:{textAlign:"center", padding:"22px", color:"var(--faint)", fontSize:"var(--t-xs)", borderTop:"1px solid var(--border)", marginTop:"auto"}},
    "Argos ", React.createElement("span",{className:"mono"},"v0.1.0-beta.4"),
    " · freie Software unter der AGPL-3.0-Lizenz");
}

Object.assign(window, { App, TasksList, Placeholder, Footer });
ReactDOM.createRoot(document.getElementById("root")).render(React.createElement(App));

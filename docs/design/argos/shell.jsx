/* ARGOS — app shell: sidebar nav, topbar, direction & theme controls */

const NAV = [
  {group:null, items:[
    {id:"overview", label:"Design-Übersicht", icon:Icon.swatch},
    {id:"dashboard", label:"Dashboard", icon:Icon.home},
  ]},
  {group:"Worker", items:[
    {id:"stacks", label:"Stacks", icon:Icon.stack},
    {id:"agent-credentials", label:"Agent-Credentials", icon:Icon.key},
    {id:"image-builds", label:"Image-Builds", icon:Icon.cube},
  ]},
  {group:"Aufgaben", items:[
    {id:"tasks", label:"Tasks", icon:Icon.tasks, count:5},
  ]},
  {group:"Konfiguration", items:[
    {id:"projekte", label:"Projekte", icon:Icon.folder},
    {id:"accounts", label:"Verknüpfte Accounts", icon:Icon.link},
    {id:"access-tokens", label:"Access-Tokens", icon:Icon.key},
    {id:"oauth-apps", label:"OAuth-Apps", icon:Icon.shield},
    {id:"api-clients", label:"API-Clients", icon:Icon.chip},
    {id:"einstellungen", label:"Einstellungen", icon:Icon.gear},
  ]},
];

function Sidebar({route, go, logo}) {
  return React.createElement("aside",{className:"sidebar"},
    React.createElement("div",{className:"sidebar-head", style:{cursor:"pointer"}, onClick:()=>go("overview")},
      React.createElement(Logo,{size:28, variant:logo||"lens"})),
    React.createElement("nav",{className:"nav"},
      NAV.map((grp,gi)=>React.createElement("div",{key:gi, className:"nav-group"},
        grp.group && React.createElement("div",{className:"nav-label"}, grp.group),
        grp.items.map(it=>React.createElement("div",{
          key:it.id,
          className:`nav-item ${route===it.id || (route==="taskdetail"&&it.id==="tasks") ? "active":""}`,
          onClick:()=>go(it.id),
        },
          React.createElement(it.icon),
          React.createElement("span",null,it.label),
          it.count!=null && React.createElement("span",{className:"count"}, it.count)
        ))
      ))
    ),
    /* usage widget */
    React.createElement("div",{className:"usage-widget"},
      React.createElement("div",{className:"usage-row"},
        React.createElement("span",null,"Claude · 5 Std."), React.createElement("span",null,"62%")),
      React.createElement("div",{className:"usage-bar"}, React.createElement("div",{className:"usage-fill", style:{width:"62%"}})),
      React.createElement("div",{className:"usage-row", style:{marginTop:10}},
        React.createElement("span",null,"Claude · 7 Tage"), React.createElement("span",null,"81%")),
      React.createElement("div",{className:"usage-bar"}, React.createElement("div",{className:"usage-fill warn", style:{width:"81%"}}))
    )
  );
}

function Topbar({crumbs, dir, setDir, theme, setTheme}) {
  return React.createElement("header",{className:"topbar"},
    React.createElement("div",{className:"crumbs"},
      crumbs.map((c,i)=>React.createElement(React.Fragment,{key:i},
        i>0 && React.createElement(Icon.chevRight,{style:{width:14,height:14,opacity:.5}}),
        c.active ? React.createElement("b",null,c.label) : React.createElement("span",null,c.label)
      ))
    ),
    React.createElement("div",{className:"spacer"}),
    /* theme toggle */
    React.createElement("button",{className:"iconbtn", title:"Light / Dark umschalten",
      onClick:()=>setTheme(theme==="light"?"dark":"light")},
      theme==="light" ? React.createElement(Icon.moon) : React.createElement(Icon.sun)),
    React.createElement("button",{className:"btn btn-secondary btn-sm"},
      React.createElement(Icon.feedback), "Feedback"),
    React.createElement(Avatar,{initials:"AA"})
  );
}

Object.assign(window, { Sidebar, Topbar, NAV });

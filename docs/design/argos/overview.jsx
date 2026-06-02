/* ARGOS — Overview: design reasoning + system spec (the deliverable doc, in-app) */

function toHex(c){
  const m = c.match(/\d+(\.\d+)?/g); if(!m) return c;
  if(c.startsWith("#")) return c;
  return "#"+m.slice(0,3).map(v=>(+v).toString(16).padStart(2,"0")).join("");
}
function Swatch({stop, prefix}) {
  const ref = useRef(null);
  const [hex, setHex] = useState("");
  useEffect(()=>{ if(ref.current) setHex(toHex(getComputedStyle(ref.current).backgroundColor)); });
  return React.createElement("div",{style:{flex:"1",minWidth:0}},
    React.createElement("div",{ref, style:{height:42, background:`var(--${prefix}-${stop})`, borderRadius:6, border:"1px solid var(--border)"}}),
    React.createElement("div",{style:{fontSize:10, color:"var(--muted)", marginTop:5, fontWeight:600}}, stop),
    React.createElement("div",{style:{fontSize:9.5, color:"var(--faint)", fontFamily:"var(--font-mono)"}}, hex));
}
function Ramp({label, prefix, stops, dir}) {
  return React.createElement("div",{style:{marginBottom:16}},
    React.createElement("div",{style:{fontSize:12, fontWeight:600, marginBottom:7}}, label),
    React.createElement("div",{style:{display:"flex", gap:6}},
      stops.map(s=>React.createElement(Swatch,{key:prefix+s+dir, stop:s, prefix}))));
}

function Overview({dir, logo, setLogo}) {
  const dirName = {1:"① Refined Indigo", 2:"② Warm Paper", 3:"③ Control Room"}[dir];
  const dirDesc = {
    1:"Konservativ — nah am heutigen Filament-Slate, nur aufgeräumt und wärmer. Sicherer Einstieg, minimaler Migrationsaufwand.",
    2:"Claude-nah — warmes Papier/Sand, Terrakotta-Akzent, großzügiger Weißraum. Ruhig, editorial, einladend.",
    3:"Mutig — dunkel-zuerst gedachter Kontrollraum: dichter, schärfere Kanten, Terminal/Mono im Vordergrund, elektrisches Iris-Violett.",
  }[dir];
  return React.createElement("div",{className:"content fade-in", style:{maxWidth:1000}},
    React.createElement("h1",{className:"page-title"},"Argos Redesign — Übersicht"),
    React.createElement("p",{className:"page-sub"},"Was du hier siehst, wie du es bedienst, und das Design-System dahinter."),

    React.createElement("div",{className:"callout callout-info", style:{marginBottom:24}},
      React.createElement(Icon.info),
      React.createElement("div",null,
        React.createElement("b",null,"So bedienst du diesen Prototyp. "),
        "Finaler Stand: ", React.createElement("b",null,"Warm Paper"), ", Layout ", React.createElement("b",null,"Thread"),
        ", Logo ", React.createElement("b",null,"Lens"), ". Oben rechts schaltest du ",
        React.createElement("b",null,"Light/Dark"), " um. Links navigierst du zu ", React.createElement("b",null,"Dashboard"),
        " und ", React.createElement("b",null,"Tasks → Task-Detail"), ". Diese Seite ist nur die Design-Übersicht — im echten Produkt existiert sie nicht.")),

    /* assumptions */
    React.createElement(Card,{className:"card-pad", style:{marginBottom:20}},
      React.createElement("h3",{style:{margin:"0 0 12px", fontSize:"var(--t-h3)"}},"Meine Annahmen"),
      React.createElement("ul",{style:{margin:0, paddingLeft:18, lineHeight:1.8, color:"var(--text-2)", fontSize:"var(--t-sm)"}},
        React.createElement("li",null,"Alles ist als ", React.createElement("b",null,"CSS-Variablen-System"), " gebaut → mappt 1:1 auf Tailwind v4 ", React.createElement("code",{className:"mono"},"@theme"), " und Filaments 50→950-Ramps. Der Handoff bleibt mechanisch."),
        React.createElement("li",null,"Status wird ", React.createElement("b",null,"nie nur über Farbe"), " kommuniziert — immer Farbe + Icon + Label (WCAG AA)."),
        React.createElement("li",null,"Heroicons-kompatible Outline-Icons, damit ihr sie 1:1 weiterverwenden könnt."),
        React.createElement("li",null,"Dark Mode ist gleichwertig mitgedacht, nicht nachträglich invertiert."),
        React.createElement("li",null,"Das ", React.createElement("b",null,"Auge + Terminal-Cursor"), " bleibt als Marke — nur sauberer gezeichnet.")
      )),

    /* committed direction */
    React.createElement(Card,{className:"card-pad", style:{marginBottom:20}},
      React.createElement("div",{style:{display:"flex", alignItems:"center", gap:10, marginBottom:6}},
        React.createElement(ArgosEye,{size:26, variant:logo}),
        React.createElement("h3",{style:{margin:0, fontSize:"var(--t-h3)", whiteSpace:"nowrap"}}, "Festgelegtes System: Warm Paper"),
        React.createElement("span",{className:"badge badge-success", style:{marginLeft:4}}, React.createElement(Icon.check), "committed")),
      React.createElement("p",{style:{margin:"0 0 18px", color:"var(--text-2)", fontSize:"var(--t-sm)"}},
        "Warmes Papier/Sand, Terrakotta-Akzent, gro\u00dfz\u00fcgiger Wei\u00dfraum \u2014 ruhig, editorial, einladend. Hier die finalen Hex-Ramps f\u00fcr den Handoff."),
      React.createElement(Ramp,{label:"Akzent (Primary) \u00b7 Terracotta", prefix:"a", dir, stops:[50,100,200,300,400,500,600,700,800,900,950]}),
      React.createElement(Ramp,{label:"Neutral \u00b7 warm sand", prefix:"n", dir, stops:[50,100,200,300,400,500,600,700,800,900,950]})
    ),

    /* logo gallery */
    React.createElement(Card,{className:"card-pad", style:{marginBottom:20}},
      React.createElement("div",{style:{display:"flex", alignItems:"center", gap:10, marginBottom:4, flexWrap:"wrap"}},
        React.createElement("h3",{style:{margin:0, fontSize:"var(--t-h3)", whiteSpace:"nowrap"}},"Logo-Marke"),
        React.createElement("span",{className:"badge badge-success"}, React.createElement(Icon.check), "Lens gew\u00e4hlt")),
      React.createElement("p",{style:{margin:"0 0 16px", color:"var(--muted)", fontSize:"var(--t-sm)"}},
        React.createElement("b",null,"Lens"), " ist final gesetzt und \u00fcberall \u00fcbernommen (auch in der Sidebar). Die anderen drei bleiben als dokumentierte Alternativen \u2014 Klick wechselt zur Vorschau."),
      React.createElement("div",{className:"logo-gallery"},
        [{id:"lens", name:"Lens", sub:"Linse + Prompt >"},
         {id:"bracket", name:"Bracket", sub:"\u2039 Iris \u203a Terminal-Klammern"},
         {id:"caret", name:"Caret", sub:"Iris-Ring + Cursor-Pupille"},
         {id:"scan", name:"Scan", sub:"Linse + Scanline"}].map(o=>
          React.createElement("div",{key:o.id, className:`logo-opt ${logo===o.id?"on":""}`, onClick:()=>setLogo&&setLogo(o.id)},
            React.createElement(ArgosEye,{size:46, variant:o.id}),
            React.createElement("div",{className:"lo-name"}, o.name),
            React.createElement("div",{className:"lo-sub"}, o.sub)))),
      React.createElement("div",{style:{marginTop:18, display:"flex", alignItems:"center", gap:14, paddingTop:16, borderTop:"1px solid var(--border)"}},
        React.createElement("span",{style:{fontSize:"var(--t-xs)", color:"var(--faint)", textTransform:"uppercase", letterSpacing:".06em", fontWeight:600}},"Vorschau"),
        React.createElement(Logo,{size:30, variant:logo}))
    ),

    /* semantics + badges */
    React.createElement("div",{className:"grid-2", style:{marginBottom:20}},
      React.createElement(Card,{className:"card-pad"},
        React.createElement("h3",{style:{margin:"0 0 14px", fontSize:"var(--t-h3)"}},"Status-Sprache"),
        React.createElement("div",{style:{display:"flex", flexWrap:"wrap", gap:10}},
          ["draft","running","waiting","success","failed"].map(s=>
            React.createElement(StatusBadge,{key:s, status:s})))
        ,
        React.createElement("div",{style:{display:"flex", flexWrap:"wrap", gap:10, marginTop:14}},
          ["Konzept","Implement","Push","Respond"].map(p=>
            React.createElement(PhaseChip,{key:p, phase:p, on:p==="Implement"})))
      ),
      React.createElement(Card,{className:"card-pad"},
        React.createElement("h3",{style:{margin:"0 0 14px", fontSize:"var(--t-h3)"}},"Buttons"),
        React.createElement("div",{style:{display:"flex", flexWrap:"wrap", gap:10}},
          React.createElement(Btn,{variant:"primary", icon:Icon.rocket},"Primär"),
          React.createElement(Btn,{variant:"secondary", icon:Icon.code},"Sekundär"),
          React.createElement(Btn,{variant:"ghost"},"Ghost"),
          React.createElement(Btn,{variant:"success", icon:Icon.check},"Erfolg"),
          React.createElement(Btn,{variant:"danger", icon:Icon.stop},"Gefahr"))
      )
    ),

    /* typography */
    React.createElement(Card,{className:"card-pad"},
      React.createElement("h3",{style:{margin:"0 0 14px", fontSize:"var(--t-h3)"}},"Typografie"),
      React.createElement("div",{style:{display:"grid", gap:10}},
        React.createElement("div",{style:{fontSize:30, fontWeight:700, letterSpacing:"-.01em"}},"Display · 30 / 700"),
        React.createElement("div",{style:{fontSize:24, fontWeight:700}},"Überschrift H1 · 24 / 700"),
        React.createElement("div",{style:{fontSize:19, fontWeight:600}},"Überschrift H2 · 19 / 600"),
        React.createElement("div",{style:{fontSize:14}},"Fließtext · 14 / 400 — der Großteil der UI lebt hier."),
        React.createElement("div",{className:"mono", style:{fontSize:13, color:"var(--accent-text)"}},"$ mono · 13 — Logs, Diffs, Branches, Wortmarke"))
    )
  );
}

Object.assign(window, { Overview });

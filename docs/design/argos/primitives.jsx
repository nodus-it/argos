/* ARGOS — reusable primitives */
const { useState, useEffect, useRef } = React;

/* Status badge — color + icon + label, never color alone */
const STATUS = {
  running:  {cls:"badge-running",  label:"Läuft",       dot:true},
  waiting:  {cls:"badge-waiting",  label:"Wartet",      icon:Icon.hand},
  success:  {cls:"badge-success",  label:"Fertig",      icon:Icon.check},
  failed:   {cls:"badge-failed",   label:"Fehlge­schlagen", icon:Icon.x},
  draft:    {cls:"badge-draft",    label:"Entwurf",     icon:Icon.doc},
};
function StatusBadge({status, label}) {
  const s = STATUS[status] || STATUS.draft;
  return React.createElement("span", {className:`badge ${s.cls}`},
    s.dot ? React.createElement("span",{className:"dot"}) : React.createElement(s.icon),
    label || s.label);
}

/* Phase chip with icon */
const PHASE = {
  Entwurf:        {icon:Icon.doc},
  Konzept:        {icon:Icon.bulb},
  Implement:      {icon:Icon.code},
  Push:           {icon:Icon.push},
  Review:         {icon:Icon.feedback},
  Respond:        {icon:Icon.feedback},
};
function PhaseChip({phase, on}) {
  const p = PHASE[phase] || PHASE.Entwurf;
  return React.createElement("span", {className:`chip ${on?"on":""}`},
    React.createElement(p.icon), phase);
}

function Btn({variant="secondary", sm, icon:Ic, children, ...p}) {
  return React.createElement("button", {className:`btn btn-${variant} ${sm?"btn-sm":""}`, ...p},
    Ic && React.createElement(Ic), children);
}

function Card({children, className="", pad, ...p}) {
  return React.createElement("div", {className:`card ${className}`, ...p},
    pad ? React.createElement("div",{className:"card-pad"}, children) : children);
}

function CardHead({icon:Ic, title, right}) {
  return React.createElement("div",{className:"card-head"},
    Ic && React.createElement(Ic,{className:"ic"}),
    React.createElement("h3",null,title),
    right && React.createElement("div",{style:{marginLeft:"auto",display:"flex",gap:8,alignItems:"center"}}, right));
}

function Avatar({initials="AA"}) {
  return React.createElement("div",{className:"avatar"}, initials);
}

function Seg({options, value, onChange}) {
  return React.createElement("div",{className:"seg"},
    options.map(o=>React.createElement("button",{
      key:o.value, className:o.value===value?"on":"",
      onClick:()=>onChange(o.value)
    }, o.label)));
}

Object.assign(window, { StatusBadge, PhaseChip, Btn, Card, CardHead, Avatar, Seg, STATUS, PHASE });

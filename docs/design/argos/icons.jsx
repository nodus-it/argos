/* ARGOS — Heroicon-style outline icons + brand mark.
   All icons share a 24-viewbox, 1.6 stroke, currentColor. */
const I = ({d, fill, vb="0 0 24 24", sw=1.6, children, ...p}) =>
  React.createElement("svg", {viewBox:vb, fill:"none", xmlns:"http://www.w3.org/2000/svg", ...p},
    children || React.createElement("path", {d, stroke:"currentColor", strokeWidth:sw, strokeLinecap:"round", strokeLinejoin:"round", fill:fill||"none"}));

const Icon = {
  home:    p=>React.createElement(I,{...p,d:"M3.5 11.5 12 4l8.5 7.5M5.5 10v9a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1v-9"}),
  stack:   p=>React.createElement(I,{...p,d:"M12 3 3 7.5l9 4.5 9-4.5L12 3ZM3 12.5 12 17l9-4.5M3 17 12 21.5 21 17"}),
  key:     p=>React.createElement(I,{...p,d:"M15.5 8.5a3.5 3.5 0 1 1-3.4 4.4L9 16h-2v2H5v2H3v-2.5l5.6-5.6A3.5 3.5 0 0 1 15.5 8.5Zm.8 1.7h.01"}),
  cube:    p=>React.createElement(I,{...p,d:"M12 3 4 7v10l8 4 8-4V7l-8-4Zm0 0v18M4 7l8 4 8-4"}),
  tasks:   p=>React.createElement(I,{...p,d:"M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"}),
  folder:  p=>React.createElement(I,{...p,d:"M3.5 7a2 2 0 0 1 2-2h3.2a2 2 0 0 1 1.4.6l1.2 1.2a2 2 0 0 0 1.4.6h5.3a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2V7Z"}),
  link:    p=>React.createElement(I,{...p,d:"M9 15l6-6M10.5 6.5l1.8-1.8a4 4 0 0 1 5.7 5.7l-1.8 1.8M13.5 17.5l-1.8 1.8a4 4 0 0 1-5.7-5.7l1.8-1.8"}),
  shield:  p=>React.createElement(I,{...p,d:"M12 3.5 5 6v5c0 4.2 2.9 7.6 7 9 4.1-1.4 7-4.8 7-9V6l-7-2.5ZM9.5 12l1.8 1.8 3.2-3.6"}),
  chip:    p=>React.createElement(I,{...p,d:"M7 7h10v10H7zM9.5 4v2.5M14.5 4v2.5M9.5 17.5V20M14.5 17.5V20M4 9.5h3M4 14.5h3M17 9.5h3M17 14.5h3"}),
  gear:    p=>React.createElement(I,{...p,d:"M12 9.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5Zm7.4 2.5a7.4 7.4 0 0 0-.1-1.1l1.9-1.4-1.9-3.3-2.2.9a7 7 0 0 0-1.9-1.1l-.3-2.4H10l-.3 2.4a7 7 0 0 0-1.9 1.1l-2.2-.9L3.7 9.5l1.9 1.4a7.4 7.4 0 0 0 0 2.2L3.7 14.5l1.9 3.3 2.2-.9a7 7 0 0 0 1.9 1.1l.3 2.4h4l.3-2.4a7 7 0 0 0 1.9-1.1l2.2.9 1.9-3.3-1.9-1.4c.06-.36.1-.73.1-1.1Z"}),
  code:    p=>React.createElement(I,{...p,d:"M9 8l-4 4 4 4M15 8l4 4-4 4"}),
  bulb:    p=>React.createElement(I,{...p,d:"M9 18h6M10 21h4M8.5 14a5 5 0 1 1 7 0c-.7.7-1 1.3-1 2.2V17h-5v-.8c0-.9-.3-1.5-1-2.2Z"}),
  push:    p=>React.createElement(I,{...p,d:"M12 16V5m0 0L7.5 9.5M12 5l4.5 4.5M5 18.5h14"}),
  refresh: p=>React.createElement(I,{...p,d:"M4.5 12a7.5 7.5 0 0 1 12.8-5.3L20 9M20 9V4m0 5h-5M19.5 12a7.5 7.5 0 0 1-12.8 5.3L4 15M4 15v5m0-5h5"}),
  stop:    p=>React.createElement(I,{...p,d:"M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM9.5 9.5h5v5h-5z"}),
  download:p=>React.createElement(I,{...p,d:"M12 4v10m0 0 4-4m-4 4-4-4M5 18.5h14"}),
  check:   p=>React.createElement(I,{...p,d:"M5 12.5 10 17l9-10"}),
  checkCircle:p=>React.createElement(I,{...p,d:"M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm-3.5-9 2.5 2.5L16 11"}),
  globe:   p=>React.createElement(I,{...p,d:"M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0 0c2.5 0 4-4 4-9s-1.5-9-4-9-4 4-4 9 1.5 9 4 9ZM3.5 9h17M3.5 15h17"}),
  bolt:    p=>React.createElement(I,{...p,d:"M13 3 5 13h5l-1 8 8-10h-5l1-8Z"}),
  rocket:  p=>React.createElement(I,{...p,d:"M5 15c-1.5 1-2 4-2 4s3-.5 4-2m2.5-1.5L7 14c-.5-3 1-7 5.5-9.5C16 2.8 19 3 19 3s.2 3-1.5 6.5C15 14 11 15.5 8 15Zm6.5-6.5h.01"}),
  clock:   p=>React.createElement(I,{...p,d:"M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0-13.5V12l3 2"}),
  chevDown:p=>React.createElement(I,{...p,d:"M6 9.5 12 15l6-5.5"}),
  chevRight:p=>React.createElement(I,{...p,d:"M9.5 6 15 12l-5.5 6"}),
  arrowLeft:p=>React.createElement(I,{...p,d:"M19 12H5m0 0 6-6m-6 6 6 6"}),
  search:  p=>React.createElement(I,{...p,d:"M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14Zm5-2 4 4"}),
  filter:  p=>React.createElement(I,{...p,d:"M4 5h16l-6.5 8v5l-3 1.5V13L4 5Z"}),
  columns: p=>React.createElement(I,{...p,d:"M5 4h14v16H5zM10 4v16M14.5 4v16"}),
  sun:     p=>React.createElement(I,{...p,d:"M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8ZM12 2v2M12 20v2M4 12H2M22 12h-2M5.6 5.6 4.2 4.2M19.8 19.8l-1.4-1.4M18.4 5.6l1.4-1.4M4.2 19.8l1.4-1.4"}),
  moon:    p=>React.createElement(I,{...p,d:"M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5Z"}),
  feedback:p=>React.createElement(I,{...p,d:"M5 5h14a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H9l-4 4v-4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z"}),
  eye:     p=>React.createElement(I,{...p,d:"M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Zm9.5 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"}),
  plus:    p=>React.createElement(I,{...p,d:"M12 5v14M5 12h14"}),
  x:       p=>React.createElement(I,{...p,d:"M6 6l12 12M18 6 6 18"}),
  external:p=>React.createElement(I,{...p,d:"M14 5h5v5M19 5l-7 7M11 6H6a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1v-5"}),
  dollar:  p=>React.createElement(I,{...p,d:"M12 3v18M15.5 7.5C15 6 13.7 5.5 12 5.5c-2 0-3.5 1-3.5 2.7 0 3.8 7 1.8 7 5.6 0 1.8-1.6 2.7-3.5 2.7-1.9 0-3.3-.6-3.8-2.2"}),
  branch:  p=>React.createElement(I,{...p,d:"M7 4v12m0 0a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5Zm0-12a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5Zm10 0a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5Zm0 5c0 4-3 5-5.5 6"}),
  send:    p=>React.createElement(I,{...p,d:"M4 12 20 4l-6 16-3.5-6.5L4 12Z"}),
  info:    p=>React.createElement(I,{...p,d:"M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0-9.5V16m0-7.5h.01"}),
  warn:    p=>React.createElement(I,{...p,d:"M12 4 2.5 20h19L12 4Zm0 6v4m0 3h.01"}),
  hand:    p=>React.createElement(I,{...p,d:"M9 11V5.5a1.5 1.5 0 0 1 3 0V11m0 0V4.5a1.5 1.5 0 0 1 3 0V11m0 0V6.5a1.5 1.5 0 0 1 3 0V14c0 3.5-2.5 6.5-6 6.5S9 18 8 16l-1.5-3a1.4 1.4 0 0 1 2.3-1.6L9 12"}),
  doc:     p=>React.createElement(I,{...p,d:"M7 3h7l4 4v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm7 0v4h4M9 13h6M9 16.5h6"}),
  swatch:  p=>React.createElement(I,{...p,d:"M5 5h6v14a3 3 0 0 1-6 0V5Zm3 11h.01M11 9.5l4-1 5 13M11 13l8 6"}),
  type:    p=>React.createElement(I,{...p,d:"M5 7V5h14v2M12 5v14M9 19h6"}),
  dots:    p=>React.createElement(I,{...p,d:"M12 6h.01M12 12h.01M12 18h.01"}),
  pr:      p=>React.createElement(I,{...p,d:"M6.5 7a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm0 4v6m0 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm11-2a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm0 0v2a3 3 0 0 1-3 3h-3m1.5-2L11 14l2 2"}),
  trash:   p=>React.createElement(I,{...p,d:"M5 7h14M9.5 7V5.5a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1V7M6.5 7l.8 12a1 1 0 0 0 1 .9h7.4a1 1 0 0 0 1-.9L17.5 7M10 11v5M14 11v5"}),
  pencil:  p=>React.createElement(I,{...p,d:"M4 20h4L18.5 9.5a2 2 0 0 0-2.8-2.8L5 17v3ZM14 8l2 2"}),
  play:    p=>React.createElement(I,{...p,d:"M8 5.5v13l11-6.5-11-6.5Z"}),
  activity:p=>React.createElement(I,{...p,d:"M3 12h4l2.5-7 5 14 2.5-7H21"}),
};

/* ---- Brand marks: all-seeing eye + terminal cursor, 4 variants ---- */
const EYES = {
  /* A — Lens: almond eye, filled iris, prompt > + cursor block */
  lens: (s)=>React.createElement("svg",{width:s,height:s,viewBox:"0 0 40 40",fill:"none",className:"eye"},
    React.createElement("path",{d:"M3 20S9.5 9 20 9s17 11 17 11-6.5 11-17 11S3 20 3 20Z",stroke:"var(--accent)",strokeWidth:2.4,strokeLinejoin:"round"}),
    React.createElement("circle",{cx:20,cy:20,r:8.4,fill:"var(--accent)"}),
    React.createElement("circle",{cx:20,cy:20,r:6,fill:"var(--surface)"}),
    React.createElement("path",{d:"M17.4 17 20 20l-2.6 3",stroke:"var(--accent)",strokeWidth:2,strokeLinecap:"round",strokeLinejoin:"round"}),
    React.createElement("rect",{x:21.4,y:21.6,width:2.6,height:1.9,rx:.4,fill:"var(--accent)"})),

  /* B — Bracket: ‹ iris › — terminal brackets form the eyelids */
  bracket: (s)=>React.createElement("svg",{width:s,height:s,viewBox:"0 0 40 40",fill:"none",className:"eye"},
    React.createElement("path",{d:"M11 11 4 20l7 9",stroke:"var(--accent)",strokeWidth:2.6,strokeLinecap:"round",strokeLinejoin:"round"}),
    React.createElement("path",{d:"M29 11l7 9-7 9",stroke:"var(--accent)",strokeWidth:2.6,strokeLinecap:"round",strokeLinejoin:"round"}),
    React.createElement("circle",{cx:20,cy:20,r:6.6,fill:"var(--accent)"}),
    React.createElement("circle",{cx:20,cy:20,r:2.4,fill:"var(--surface)"})),

  /* C — Caret: iris ring with a blinking-cursor pupil */
  caret: (s)=>React.createElement("svg",{width:s,height:s,viewBox:"0 0 40 40",fill:"none",className:"eye"},
    React.createElement("circle",{cx:20,cy:20,r:11,stroke:"var(--accent)",strokeWidth:2.6}),
    React.createElement("rect",{x:18,y:14,width:4,height:12,rx:1,fill:"var(--accent)"})),

  /* D — Scan: almond lens with iris ring + horizontal scanline */
  scan: (s)=>React.createElement("svg",{width:s,height:s,viewBox:"0 0 40 40",fill:"none",className:"eye"},
    React.createElement("path",{d:"M3 20S9.5 9 20 9s17 11 17 11-6.5 11-17 11S3 20 3 20Z",stroke:"var(--accent)",strokeWidth:2.4,strokeLinejoin:"round"}),
    React.createElement("circle",{cx:20,cy:20,r:6.5,stroke:"var(--accent)",strokeWidth:2.4}),
    React.createElement("path",{d:"M6 20h7M27 20h7",stroke:"var(--accent)",strokeWidth:2.4,strokeLinecap:"round"}),
    React.createElement("circle",{cx:20,cy:20,r:1.8,fill:"var(--accent)"})),
};

const ArgosEye = ({size=30, variant="lens", ...p}) =>
  React.createElement("span",{style:{display:"inline-flex"}, ...p}, (EYES[variant]||EYES.lens)(size));

const Logo = ({size=30, variant="lens"}) =>
  React.createElement("div", {className:"brand"},
    React.createElement(ArgosEye, {size, variant}),
    React.createElement("span", {className:"word"}, "ARGOS"));

Object.assign(window, { Icon, ArgosEye, Logo, EYES });

/* ARGOS — mock data for the prototype */
/* rail states per phase [Entwurf, Konzept, Implement, Push, Review]:
   'done' | 'active' | 'wait' | 'fail' | 'todo'  */
const RAIL_PHASES = ["Entwurf","Konzept","Implement","Push","Review"];
const TASKS = [
  {
    id:"basis-laravel", name:"Basis Laravel", project:"argos-test", source:"—",
    workflow:"Implementierung abgeschlossen", workflowStatus:"success",
    phase:"Implement", agent:"Claude Code", status:"running", activity:"vor 1 Stunde",
    desc:"Füge in dem Projekt eine Basis Laravel installation hinzu",
    repo:"argos-test", stack:"php-8.4", baseBranch:"main", branch:"feat/Basis-Laravel",
    cost:"$0.9753", tokens:"11.827", created:"02.06.2026 06:43", pr:null,
    rail:["done","done","done","active","todo"],
    demo:{url:"http://demo-basis-laravel.127.0.0.1.nip.io:8080", expires:"in 23 Stunden", live:true},
  },
  {
    id:"hello-world", name:"Hello World page", project:"argos-test", source:"—",
    workflow:"In Review", workflowStatus:"running",
    phase:"Review", agent:"Claude Code", status:"waiting", activity:"vor 1 Stunde",
    desc:"Erstelle eine simple Hello-World-Seite mit Routing.",
    repo:"argos-test", stack:"php-8.4", baseBranch:"main", branch:"feat/Hello-World-page",
    cost:"$0.3290", tokens:"4.227", created:"02.06.2026 06:23", pr:"#22",
    rail:["done","done","done","done","wait"],
    demo:{url:"http://demo-hello-world.127.0.0.1.nip.io:8080", expires:"in 21 Stunden", live:true},
  },
  {
    id:"auth-flow", name:"Login & Session Auth", project:"argos-test", source:"GH #142",
    workflow:"Wartet auf Feedback", workflowStatus:"waiting",
    phase:"Review", agent:"Claude Code", status:"waiting", activity:"vor 22 Minuten",
    desc:"Implementiere Login, Logout und Session-basierte Authentifizierung.",
    repo:"argos-test", stack:"php-8.4", baseBranch:"main", branch:"feat/auth-flow",
    cost:"$1.84", tokens:"24.510", created:"02.06.2026 09:10", pr:"#19",
    rail:["done","done","done","done","wait"],
    demo:{live:false},
  },
  {
    id:"api-ratelimit", name:"API Rate-Limiting", project:"platform", source:"GL !88",
    workflow:"Konzept läuft", workflowStatus:"running",
    phase:"Konzept", agent:"Codex", status:"running", activity:"gerade eben",
    desc:"Füge Rate-Limiting pro API-Client mit Redis-Buckets hinzu.",
    repo:"platform", stack:"node-22", baseBranch:"develop", branch:"feat/ratelimit",
    cost:"$0.12", tokens:"1.940", created:"02.06.2026 10:02", pr:null,
    rail:["done","active","todo","todo","todo"],
    demo:{live:false},
  },
  {
    id:"migrate-fail", name:"Postgres-Migration", project:"platform", source:"GL !90",
    workflow:"Implementierung fehlgeschlagen", workflowStatus:"failed",
    phase:"Implement", agent:"Claude Code", status:"failed", activity:"vor 3 Stunden",
    desc:"Migriere das Schema von MySQL auf Postgres inkl. Daten.",
    repo:"platform", stack:"node-22", baseBranch:"develop", branch:"chore/pg-migrate",
    cost:"$0.77", tokens:"9.330", created:"02.06.2026 07:15", pr:null,
    rail:["done","done","fail","todo","todo"],
    demo:{live:false},
  },
  {
    id:"test-default", name:"test-default", project:"test", source:"—",
    workflow:"Entwurf", workflowStatus:"draft",
    phase:"Entwurf", agent:"Claude Code", status:"draft", activity:"vor 10 Stunden",
    desc:"Platzhalter-Task zum Testen der Pipeline.",
    repo:"test", stack:"php-8.4", baseBranch:"main", branch:"—",
    cost:"$0.00", tokens:"0", created:"02.06.2026 00:30", pr:null,
    rail:["active","todo","todo","todo","todo"],
    demo:{live:false},
  },
];

const LOG_LINES = [
  {t:"06:47:02", k:"t-cmd",    x:"$ argos worker run --task basis-laravel --agent claude-code"},
  {t:"06:47:02", k:"t-info",   x:"→ spinning up isolated worker  image=php-8.4  mem=2G"},
  {t:"06:47:04", k:"t-ok",     x:"✓ container ready  id=wrk_9f2a1c"},
  {t:"06:47:04", k:"t-info",   x:"→ cloning git@argos-test  branch=feat/Basis-Laravel"},
  {t:"06:47:07", k:"t-ok",     x:"✓ checkout complete  (1.2s, 412 objects)"},
  {t:"06:47:08", k:"t-accent", x:"agent> reading concept & repository structure…"},
  {t:"06:47:12", k:"t-info",   x:"composer create-project laravel/laravel ."},
  {t:"06:47:39", k:"t-ok",     x:"✓ 78 packages installed"},
  {t:"06:47:40", k:"t-info",   x:"php artisan key:generate"},
  {t:"06:47:41", k:"t-info",   x:"npm install && npm run build"},
  {t:"06:48:15", k:"t-ok",     x:"✓ vite build  →  public/build (412kb)"},
  {t:"06:48:16", k:"t-accent", x:"agent> writing 2 smoke tests (welcome route, db connection)"},
  {t:"06:48:22", k:"t-info",   x:"php artisan test"},
  {t:"06:48:29", k:"t-warn",   x:"! Pest not present in skeleton — falling back to PHPUnit 12"},
  {t:"06:48:31", k:"t-ok",     x:"✓ Tests: 2 passed (4 assertions)  0.84s"},
  {t:"06:48:32", k:"t-ok",     x:"✓ implementation phase complete  cost=$0.7133  tokens=11,827"},
];

const DIFF = [
  {file:"app/Http/Controllers/WelcomeController.php", add:24, del:0, rows:[
    {t:"hunk", n1:"", n2:"", c:"@@ -0,0 +1,24 @@ new file"},
    {t:"add", n1:"", n2:1, c:"<?php"},
    {t:"add", n1:"", n2:2, c:""},
    {t:"add", n1:"", n2:3, c:"namespace App\\Http\\Controllers;"},
    {t:"add", n1:"", n2:4, c:""},
    {t:"add", n1:"", n2:5, c:"class WelcomeController extends Controller"},
    {t:"add", n1:"", n2:6, c:"{"},
    {t:"add", n1:"", n2:7, c:"    public function index()"},
    {t:"add", n1:"", n2:8, c:"    {"},
    {t:"add", n1:"", n2:9, c:"        return view('welcome');"},
    {t:"add", n1:"", n2:10, c:"    }"},
    {t:"add", n1:"", n2:11, c:"}"},
  ]},
  {file:"routes/web.php", add:3, del:1, rows:[
    {t:"hunk", n1:"", n2:"", c:"@@ -1,5 +1,7 @@"},
    {t:"ctx", n1:1, n2:1, c:"<?php"},
    {t:"ctx", n1:2, n2:2, c:""},
    {t:"ctx", n1:3, n2:3, c:"use Illuminate\\Support\\Facades\\Route;"},
    {t:"del", n1:5, n2:"", c:"Route::get('/', fn () => view('welcome'));"},
    {t:"add", n1:"", n2:5, c:"use App\\Http\\Controllers\\WelcomeController;"},
    {t:"add", n1:"", n2:6, c:""},
    {t:"add", n1:"", n2:7, c:"Route::get('/', [WelcomeController::class, 'index']);"},
  ]},
  {file:"README.md", add:9, del:0, rows:[
    {t:"hunk", n1:"", n2:"", c:"@@ -10,0 +11,9 @@ ## Setup"},
    {t:"add", n1:"", n2:11, c:"## Lokales Setup"},
    {t:"add", n1:"", n2:12, c:""},
    {t:"add", n1:"", n2:13, c:"```bash"},
    {t:"add", n1:"", n2:14, c:"composer install && npm install"},
    {t:"add", n1:"", n2:15, c:"cp .env.example .env && php artisan key:generate"},
    {t:"add", n1:"", n2:16, c:"php artisan migrate && npm run build"},
    {t:"add", n1:"", n2:17, c:"php artisan serve"},
    {t:"add", n1:"", n2:18, c:"```"},
  ]},
];

/* activity feed (for Thread layout + Aktivität tab) */
const ACTIVITY = [
  {phase:"Entwurf",  status:"done", time:"06:23", cost:null,      title:"Task angelegt", who:"Du",
   body:"Task erstellt und Repository argos-test · main verknüpft."},
  {phase:"Konzept",  status:"done", time:"06:24", cost:"$0.2620", title:"Konzept erstellt", who:"Claude Code",
   body:"Ansatz festgelegt: Welcome-Route in einen Controller auslagern, SQLite einrichten, Vite-Build, 2 Smoke-Tests, README-Setup. Feature-Branch feat/Basis-Laravel angelegt."},
  {phase:"Implement",status:"done", time:"06:47", cost:"$0.7133", title:"Implementierung abgeschlossen", who:"Claude Code",
   body:"11 Dateien geändert (+36 −1). Tests grün (2 passed). Abweichung: PHPUnit statt Pest, da nicht im Laravel-13-Skeleton enthalten."},
  {phase:"Push",     status:"done", time:"06:51", cost:null,      title:"Pull Request geöffnet", who:"Claude Code",
   body:"PR #22 gegen main geöffnet — bereit zum Review."},
];

Object.assign(window, { TASKS, LOG_LINES, DIFF, RAIL_PHASES, ACTIVITY });

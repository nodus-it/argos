{{-- Live preview of the workspace (members-only demo on demo-ws-<slug>). --}}
@livewire('workspace.preview-panel', ['workspaceId' => $workspace->id], key('preview-'.$workspace->id))

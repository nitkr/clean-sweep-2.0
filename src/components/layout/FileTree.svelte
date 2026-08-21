<script>
  import { onMount } from 'svelte';
  import { files } from '../../lib/stores/files.js';

  // Search/filter state
  let searchQuery = $state('');
  let filteredTree = $state([]);

  // Load root directory on mount
  onMount(() => {
    if ($files.tree.length === 0 && !$files.loading) {
      files.loadRoot();
    }
  });

  // Filter tree based on search query
  $effect(() => {
    if (searchQuery.trim() === '') {
      filteredTree = $files.tree;
    } else {
      const query = searchQuery.toLowerCase();
      filteredTree = filterTree($files.tree, query);
    }
  });

  function filterTree(nodes, query) {
    let result = [];
    for (const node of nodes) {
      if (node.name.toLowerCase().includes(query)) {
        result.push(node);
      } else if (node.type === 'folder' && node.children) {
        const filteredChildren = filterTree(node.children, query);
        if (filteredChildren.length > 0) {
          result.push({ ...node, children: filteredChildren });
        }
      }
    }
    return result;
  }

  // Track expanded state per folder path
  let expandedPaths = $state({});

  function toggleFolder(path) {
    expandedPaths = {
      ...expandedPaths,
      [path]: !expandedPaths[path]
    };
  }

  function isExpanded(path) {
    return expandedPaths[path] === true;
  }

  // Get muted modern file icon color
  function getFileIconColor(name) {
    if (name.endsWith('.php')) return 'text-rose-700/70 dark:text-rose-400/70';
    if (name.endsWith('.js') || name.endsWith('.mjs')) return 'text-amber-700/70 dark:text-amber-400/70';
    if (name.endsWith('.ts') || name.endsWith('.tsx')) return 'text-blue-700/70 dark:text-blue-400/70';
    if (name.endsWith('.css') || name.endsWith('.scss')) return 'text-violet-700/70 dark:text-violet-400/70';
    if (name.endsWith('.json')) return 'text-emerald-700/70 dark:text-emerald-400/70';
    if (name.endsWith('.html')) return 'text-orange-700/70 dark:text-orange-400/70';
    if (name.endsWith('.md')) return 'text-slate-400/70';
    if (name.endsWith('.sql')) return 'text-cyan-700/70 dark:text-cyan-400/70';
    if (name.endsWith('.xml')) return 'text-yellow-700/70 dark:text-yellow-400/70';
    if (name.endsWith('.env') || name.endsWith('.htaccess')) return 'text-red-700 dark:text-red-400/70';
    return 'text-slate-500/70';
  }

  // Get folder icon with muted colors
  function getFolderIcon(name) {
    return 'text-slate-400/70';
  }

  // Get git/status badge
  function getStatusBadge(node) {
    if (node.infected) {
      return { marker: '●', color: 'text-red-500', label: 'Infected' };
    }
    if (node.modified) {
      return { marker: '●', color: 'text-amber-500', label: 'Modified' };
    }
    if (node.riskLevel === 'warning') {
      return { marker: '●', color: 'text-yellow-500', label: 'Warning' };
    }
    return null;
  }

  // Build breadcrumb path from selected file
  function getBreadcrumb(path) {
    if (!path) return '';
    const parts = path.split('/');
    if (parts.length <= 3) return path;
    return '...' + parts.slice(-2).join('/');
  }

  function handleClick(node) {
    files.selectFile(node);
  }

  function handleFolderClick(node) {
    files.selectFile(node);
    toggleFolder(node.path);
  }

  // Build flat list for rendering with tree lines info
  function buildTreeLines(nodes, depth = 0, parentExpanded = true) {
    let result = [];
    for (let i = 0; i < nodes.length; i++) {
      const node = nodes[i];
      const isLast = i === nodes.length - 1;
      const expanded = parentExpanded && isExpanded(node.path);

      result.push({ ...node, depth, isLast, expanded, parentExpanded });

      if (node.type === 'folder' && node.children && node.children.length > 0 && expanded) {
        result = result.concat(buildTreeLines(node.children, depth + 1, expanded));
      }
    }
    return result;
  }

  let flatTree = $derived(buildTreeLines(filteredTree.length > 0 || searchQuery ? filteredTree : $files.tree));
</script>

<div class="flex flex-col h-full bg-transparent">
  <!-- Search Input -->
  <div class="px-2 py-2 border-b border-line">
    <div class="relative">
      <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-faint" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
      </svg>
      <input
        type="text"
        bind:value={searchQuery}
        placeholder="Search files..."
        class="w-full h-7 pl-8 pr-3 text-xs bg-app border border-line rounded-md text-ink placeholder:text-faint focus:outline-none focus:border-primary/50 focus:ring-1 focus:ring-primary/20 transition-colors"
      />
    </div>
  </div>

  <!-- Current path breadcrumb -->
  {#if $files.selectedFile}
    <div class="px-3 py-1.5 text-xs text-muted border-b border-line bg-app/50">
      <span class="truncate">{getBreadcrumb($files.selectedFile.path)}</span>
    </div>
  {/if}

  <!-- File Tree -->
  <div class="flex-1 overflow-y-auto py-1">
    {#if $files.loading && $files.tree.length === 0}
      <!-- Loading state -->
      <div class="px-3 py-4 text-xs text-faint text-center">
        <div class="flex items-center justify-center gap-2">
          <svg class="w-4 h-4 animate-spin text-faint" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span>Loading files...</span>
        </div>
      </div>
    {:else if flatTree.length === 0}
      <div class="px-3 py-4 text-xs text-faint text-center">
        {searchQuery ? 'No files match your search' : 'No files found'}
      </div>
    {:else}
      {#each flatTree as node}
        {@const status = getStatusBadge(node)}
        {@const hasChildren = node.type === 'folder' && node.children && node.children.length > 0}
        {@const isActive = $files.selectedFile?.path === node.path}

        <div
          class="group relative flex items-center gap-1.5 h-7 pr-2 text-xs cursor-pointer transition-all duration-75
            {isActive ? 'bg-primary/10 text-primary' : 'text-muted hover:bg-hover hover:text-ink'}"
          style="padding-left: {12 + node.depth * 16}px;"
          onclick={() => node.type === 'folder' ? handleFolderClick(node) : handleClick(node)}
          onkeydown={(e) => e.key === 'Enter' && (node.type === 'folder' ? handleFolderClick(node) : handleClick(node))}
          role="button"
          tabindex="0"
        >
          <!-- Tree connecting lines -->
          {#each Array(node.depth) as _, i}
            {@const prevNode = flatTree.find(n => n.depth === i && n.path.startsWith(node.path.split('/').slice(0, i + 1).join('/')) && n !== node)}
            {@const lineNode = flatTree.filter(n => n.depth === i)}
            {#if i < node.depth}
              <!-- Draw vertical line for parent folders -->
              <div
                class="absolute top-0 w-px h-full bg-hover"
                style="left: {12 + i * 16 + 7}px;"
              ></div>
            {/if}
          {/each}

          <!-- Folder expand arrow -->
          {#if node.type === 'folder'}
            <svg
              class="w-3 h-3 text-faint transition-transform duration-150 {isExpanded(node.path) ? 'rotate-90' : ''} group-hover:text-muted"
              fill="currentColor"
              viewBox="0 0 20 20"
            >
              <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/>
            </svg>
          {:else}
            <span class="w-3"></span>
          {/if}

          <!-- File/Folder icon -->
          {#if node.type === 'folder'}
            <svg class="w-4 h-4 {getFolderIcon(node.name)}" fill="currentColor" viewBox="0 0 20 20">
              <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
            </svg>
          {:else}
            <svg class="w-4 h-4 {getFileIconColor(node.name)}" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
          {/if}

          <!-- File/Folder name -->
          <span class="truncate flex-1 {isActive ? 'text-primary' : ''}">{node.name}</span>

          <!-- Status badge -->
          {#if status}
            <span class="{status.color} opacity-0 group-hover:opacity-100 transition-opacity" title={status.label}>
              {status.marker}
            </span>
          {/if}
        </div>
      {/each}
    {/if}
  </div>

  <!-- Footer with file count -->
  <div class="px-3 py-1.5 text-[10px] text-faint border-t border-line flex items-center justify-between">
    <span>{flatTree.filter(n => n.type === 'file').length} files</span>
    <span>{flatTree.filter(n => n.type === 'folder').length} folders</span>
  </div>
</div>

<style>
  /* Custom scrollbar */
  div::-webkit-scrollbar {
    width: 6px;
  }

  div::-webkit-scrollbar-track {
    background: transparent;
  }

  div::-webkit-scrollbar-thumb {
    background: #27272A;
    border-radius: 3px;
  }

  div::-webkit-scrollbar-thumb:hover {
    background: #3f3f46;
  }
</style>
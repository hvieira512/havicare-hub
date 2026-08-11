-- Collect LSP diagnostics for every file passed on the command line.
--
-- Intelephense only publishes diagnostics for open documents, so a whole-project
-- report means loading every file and waiting for the server to answer. Run it as:
--
--   nvim --headless -u ~/.config/nvim/init.lua \
--     -l scripts/lsp-diagnostics.lua $(git ls-files '*.php')
--
-- Files are opened in batches so the server is not handed 400 documents at once.

local files = vim.v.argv
local targets = {}
local seen_script = false
for _, arg in ipairs(files) do
  if seen_script then
    table.insert(targets, arg)
  elseif arg:match("lsp%-diagnostics%.lua$") then
    seen_script = true
  end
end

if #targets == 0 then
  io.stderr:write("usage: nvim --headless -l scripts/lsp-diagnostics.lua <files...>\n")
  vim.cmd("cquit 2")
end

-- Diagnostics arrive asynchronously, so a batch is only considered done once the
-- count has stopped moving for several consecutive polls. Too short a settle and
-- the run silently under-reports, which is worse than being slow.
local BATCH = 40
local SETTLE_MS = 500
local STABLE_POLLS = 4
local MAX_WAIT_MS = 60000

local function wait_for_idle(bufs)
  local deadline = vim.loop.now() + MAX_WAIT_MS
  local stable_since = nil
  local previous = -1
  while vim.loop.now() < deadline do
    vim.wait(SETTLE_MS, function() return false end)
    local total = 0
    for _, buf in ipairs(bufs) do
      total = total + #vim.diagnostic.get(buf)
    end
    local pending = false
    for _, buf in ipairs(bufs) do
      if #vim.lsp.get_clients({ bufnr = buf }) == 0 then pending = true end
    end
    if not pending and total == previous then
      stable_since = (stable_since or 0) + 1
      if stable_since >= STABLE_POLLS then return end
    else
      stable_since = 0
    end
    previous = total
  end
end

local found = {}
for start = 1, #targets, BATCH do
  local bufs = {}
  for i = start, math.min(start + BATCH - 1, #targets) do
    local buf = vim.fn.bufadd(targets[i])
    vim.fn.bufload(buf)
    vim.api.nvim_set_current_buf(buf)
    table.insert(bufs, buf)
  end

  wait_for_idle(bufs)

  for _, buf in ipairs(bufs) do
    local name = vim.api.nvim_buf_get_name(buf)
    for _, d in ipairs(vim.diagnostic.get(buf)) do
      table.insert(found, string.format(
        "%s:%d:%d: [%s] %s",
        vim.fn.fnamemodify(name, ":."),
        d.lnum + 1,
        d.col + 1,
        d.source or "lsp",
        (d.message or ""):gsub("%s+", " ")
      ))
    end
  end

  -- Release the batch so the server is not holding every document at once.
  for _, buf in ipairs(bufs) do
    pcall(vim.api.nvim_buf_delete, buf, { force = true })
  end
end

table.sort(found)
for _, line in ipairs(found) do
  io.stdout:write(line .. "\n")
end
io.stdout:write(string.format("\n%d diagnostic(s) across %d file(s)\n", #found, #targets))

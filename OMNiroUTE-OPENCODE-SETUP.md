# OmniRoute + OpenCode Setup Guide

## ✅ Prerequisites Completed
- Node.js & npm installed
- OpenCode installed globally (`opencode v2.1.3`)
- `@omniroute/opencode-provider` plugin installed (`npm install -g @omniroute/opencode-provider`)

## 📦 Step 1: Ensure OmniRoute is Running

```bash
# Start OmniRoute (keep this terminal running)
omniroute
```

Dashboard: http://localhost:20128/dashboard
API: http://localhost:20128

## 🔧 Step 2: Configure OpenCode with OmniRoute Provider

The `@omniroute/opencode-provider` plugin auto-registers OmniRoute. You just need to add your API key.

### Option A: Using Environment Variable (Recommended)

```bash
# Set your OmniRoute API key
export OMNIIROUTE_API_KEY="sk-or-XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX"

# Or add to your shell config (~/.zshrc or ~/.bash_profile):
echo 'export OMNIIROUTE_API_KEY="sk-or-XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX"' >> ~/.zshrc
source ~/.zshrc
```

### Option B: Direct Configuration in opencode.json

Edit `~/.config/opencode/opencode.json` and add the omniroute provider:

```json
{
  "providers": {
    "omniroute": {
      "npm": "@omniroute/opencode-provider",
      "options": {
        "baseURL": "http://localhost:20128/v1",
        "apiKey": "{env:OMNIIROUTE_API_KEY}"
      },
      "models": {
        "auto": { "name": "auto" },
        "auto/coding": { "name": "auto/coding" },
        "auto/fast": { "name": "auto/fast" },
        "auto/cheap": { "name": "auto/cheap" }
      }
    }
  }
}
```

**Important**: Do not remove existing keys like `mcp`, `plugin`, or other providers.

## 🚀 Step 3: Verify the Setup

### Restart OpenCode

```bash
# If OpenCode is running, restart it
opencode restart
# Or simply close and reopen
```

### Test the Connection

```bash
# List available models through OmniRoute
opencode /models
```

You should see OmniRoute models listed, including:
- `omniroute/auto`
- `omniroute/auto/coding`
- `omniroute/auto/fast`
- `omniroute/auto/cheap`
- And many more (375+ providers, 1475+ models)

### Test a Quick Generation

```bash
# Set the model and test
opencode --model omniroute/auto "Hello, write a simple hello world function"
```

## 🔑 Step 4: Obtain Your OmniRoute API Key

If you don't have an API key yet:

1. **Sign up** at: https://platform.cheaperinference.com/signup?utm_source=omniroute&utm_medium=owned-site&utm_campaign=acquisition

2. **Connect your AI provider accounts** (OpenAI, Anthropic, Claude, Gemini, etc.) in the OmniRoute dashboard

3. **Generate/API key** will be available in your OmniRoute dashboard

4. **Set the key** via environment variable or opencode.json as shown above

## 📚 Step 5: Available Models & Usage

### Popular Models Through OmniRoute:

| Model ID | Description |
|---|---|
| `omniroute/auto` | Auto-routing (recommended) |
| `omniroute/auto/coding` | Optimized for coding tasks |
| `omniroute/auto/fast` | Fastest response time |
| `omniroute/auto/cheap` | Most cost-effective |
| `omniroute/anthropic/claude-3-5-sonnet` | Claude 3.5 Sonnet |
| `omniroute/openai/gpt-4o` | GPT-4o |
| `omniroute/google/gemini-1.5-pro` | Gemini 1.5 Pro |

### Token Savings

OmniRoute typically saves **15-95% tokens** (avg ~89%) through RTK + Caveman compression.

## 🛠️ Troubleshooting

### Provider Not Appearing

1. Ensure OmniRoute is running (`omniroute` command active)
2. Verify API key is valid
3. Restart OpenCode completely
4. Check config: `opencode --validate`

### Authentication Errors

```bash
# Re-authenticate
opencode auth login --provider omniroute
```

### Models Not Loading

1. Check OmniRoute dashboard at http://localhost:20128/dashboard
2. Verify connected provider accounts have available tokens
3. Check `/models` output for error messages

### Connection Refused

```bash
# Verify OmniRoute is running
curl http://localhost:20128/v1/models

# Restart if needed
pkill omniroute
omniroute
```

## 💡 Best Practices

1. **Use `omniroute/auto`** for general purpose - OmniRoute will select the best model
2. **Use `omniroute/auto/coding`** for code generation tasks
3. **Set `OMNIIROUTE_API_KEY`** as an environment variable for security
4. **Monitor free tier usage** at http://localhost:20128/dashboard/free-tiers
5. **Keep OmniRoute running** in a background terminal during your OpenCode sessions

## 📋 Quick Commands Summary

```bash
# Install (already done)
npm install -g @omniroute/opencode-provider

# Start OmniRoute
omniroute

# Set API key
export OMNIIROUTE_API_KEY="sk-or-..."

# List models
opencode /models

# Use a model
opencode --model omniroute/auto "Your prompt here"
```

---

**✅ Setup Complete!** You can now use OmniRoute with OpenCode for access to 375+ AI providers through a single endpoint.
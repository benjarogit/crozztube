# Discord requirements for watch parties

Status: required before the watch-party gate can start. Nothing here is installed or tested.

CrozzTube must not receive or store Discord voice, video or screen-share media. The bot only creates and removes temporary channels and posts coordination messages.

## Application

- One Discord application owned by the CrozzTube operator.
- OAuth2 redirect URL on the CrozzTube origin, for example `https://crozztube.local:8443/account/discord/callback`.
- Scopes: `identify` and `guilds.join` only if the user must be added to the server. Do not request message content or voice receive scopes.
- Bot token, OAuth client secret and state key stay in the environment. Never commit them.

## Server permissions

The bot needs, only in the configured watch-party category:

- View channels
- Manage channels
- Send messages
- Create public threads
- Send messages in threads
- Connect
- Mute members and move members, used solely for the 60-minute afterglow cleanup
- Manage threads

Privileged intents are not required. Do not enable the message content intent.

## Runtime configuration

- `DISCORD_GUILD_ID`
- `DISCORD_CLIENT_ID`
- `DISCORD_CLIENT_SECRET`
- `DISCORD_BOT_TOKEN`
- `DISCORD_PARTY_CATEGORY_ID`
- `DISCORD_REDIRECT_URI`

Each value must be documented as present or absent in the operator environment before implementation. A missing value keeps the feature gate off.

## Behaviour already decided

- One linked Discord account per CrozzTube account.
- One temporary voice channel and one text thread per party.
- The CrozzTube party ends at the video end. Discord remains for 30 minutes with a warning, then the bot disconnects remaining members and deletes the channel and thread.
- No recording of voice, video or chat.
- A later Discord Activity is a separate feasibility study, not part of this setup.

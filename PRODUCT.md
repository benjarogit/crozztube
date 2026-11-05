# CrozzTube

Streaming video portal for viewers, creators importing video URLs, and administrators. Videos play from external sources; thumbnail copies are hosted externally. The primary viewer task is finding and watching videos and Shorts. Creator and administration screens support content and account management.

The viewer interface prioritizes video previews and uninterrupted playback. Task screens prioritize readable fields, reachable actions and predictable navigation. Support touch, pointer and keyboard, long German and English text, light/dark themes, 320px phones through large desktop windows, landscape and resized windows.

Keep URL-import permissions, source resolution, thumbnail provider priority and bounded night maintenance intact. Never enable video-file uploads as part of frontend work. Existing feature switches and permission states remain meaningful. Tests must use bounded media traffic and one browser worker.

Image attachments in messages, communities/events and comments/replies are hosted externally immediately after selection. Preserve open browser drafts on errors; never publish while a selected image is pending or failed. Direct hoster links are public outside CrozzTube; permission and purchase checks still control whether the API exposes them. Attachments and thumbnails share one bounded night-maintenance budget and transfer lock. Avatar/banner storage is outside this change.

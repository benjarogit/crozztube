import { Room, RoomEvent, Track } from 'livekit-client';

const root = document.getElementById('recording-root');
const status = document.getElementById('recording-status');
const parameters = new URLSearchParams(window.location.search);
const serverUrl = parameters.get('url') || '';
const token = parameters.get('token') || '';
const room = new Room({ adaptiveStream: false, dynacast: true });
let attachedElements = [];
let recordingStarted = false;

function participantMetadata(participant) {
  try {
    const metadata = JSON.parse(participant.metadata || '{}');
    return metadata && typeof metadata === 'object' ? metadata : {};
  } catch (_) {
    return {};
  }
}

function participantName(participant) {
  const metadata = participantMetadata(participant);
  return participant.name || metadata.name || metadata.username || participant.identity || 'Participant';
}

function participantHasPublishedMedia(participant) {
  return Array.from(participant.trackPublications.values()).some((publication) => (
    publication.kind === Track.Kind.Audio || publication.kind === Track.Kind.Video
  ));
}

function detachTracks() {
  attachedElements.forEach(({ track, element }) => track.detach(element));
  attachedElements = [];
}

function attachTrack(track, element) {
  track.attach(element);
  attachedElements.push({ track, element });
  element.play?.().catch?.(() => {});
}

function renderRoom() {
  detachTracks();
  root.replaceChildren();
  const participants = Array.from(room.remoteParticipants.values()).filter((participant) => (
    !participantMetadata(participant).hidden && participantHasPublishedMedia(participant)
  ));
  const count = Math.max(1, participants.length);
  const columns = count === 1 ? 1 : count <= 4 ? 2 : count <= 6 ? 3 : Math.ceil(Math.sqrt(count));
  const rows = Math.ceil(count / columns);
  root.style.gridTemplateColumns = `repeat(${columns},minmax(0,1fr))`;
  root.style.gridTemplateRows = `repeat(${rows},minmax(0,1fr))`;

  participants.forEach((participant) => {
    const displayName = participantName(participant).trim() || 'Participant';
    const publications = Array.from(participant.trackPublications.values());
    const videoPublication = publications.find((publication) => publication.source === Track.Source.ScreenShare && publication.track && !publication.isMuted)
      || publications.find((publication) => publication.source === Track.Source.Camera && publication.track && !publication.isMuted)
      || publications.find((publication) => publication.kind === Track.Kind.Video && publication.track && !publication.isMuted);
    const audioPublications = publications.filter((publication) => publication.kind === Track.Kind.Audio && publication.track && !publication.isMuted);
    const tile = document.createElement('section');
    tile.className = `recording-tile${!videoPublication?.track && audioPublications.length ? ' audio-only' : ''}`;

    if (videoPublication?.track) {
      const video = document.createElement('video');
      video.autoplay = true;
      video.playsInline = true;
      video.muted = true;
      tile.appendChild(video);
      attachTrack(videoPublication.track, video);
    } else {
      const fallback = document.createElement('div');
      fallback.className = audioPublications.length ? 'recording-fallback recording-audio-card' : 'recording-fallback';
      if (audioPublications.length) {
        const avatar = document.createElement('div');
        avatar.className = 'recording-audio-avatar';
        avatar.textContent = displayName.charAt(0).toUpperCase() || '?';
        const details = document.createElement('div');
        details.className = 'recording-audio-details';
        const title = document.createElement('strong');
        title.textContent = displayName;
        const subtitle = document.createElement('span');
        subtitle.textContent = 'Audio only';
        details.append(title, subtitle);
        const meter = document.createElement('div');
        meter.className = 'recording-audio-meter';
        for (let index = 0; index < 5; index += 1) {
          meter.appendChild(document.createElement('i'));
        }
        fallback.append(avatar, details, meter);
      } else {
        fallback.textContent = displayName.charAt(0).toUpperCase() || '?';
      }
      tile.appendChild(fallback);
    }

    audioPublications.forEach((publication) => {
      const audio = document.createElement('audio');
      audio.autoplay = true;
      tile.appendChild(audio);
      attachTrack(publication.track, audio);
    });

    const name = document.createElement('span');
    name.className = 'recording-name';
    name.textContent = displayName;
    tile.appendChild(name);
    root.appendChild(tile);
  });

  status.hidden = participants.length > 0;
  status.style.display = participants.length > 0 ? 'none' : 'grid';
}

function startRecording() {
  if (recordingStarted) return;
  recordingStarted = true;
  window.requestAnimationFrame(() => window.requestAnimationFrame(() => console.log('START_RECORDING')));
}

function recordingMediaReady() {
  const readyVideo = root.querySelector('video');
  if (readyVideo && readyVideo.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA && readyVideo.videoWidth > 0) {
    return true;
  }

  return Boolean(root.querySelector('.recording-tile.audio-only .recording-audio-card'));
}

[
  RoomEvent.ParticipantConnected,
  RoomEvent.ParticipantDisconnected,
  RoomEvent.ParticipantNameChanged,
  RoomEvent.ParticipantMetadataChanged,
  RoomEvent.TrackPublished,
  RoomEvent.TrackUnpublished,
  RoomEvent.TrackSubscribed,
  RoomEvent.TrackUnsubscribed,
  RoomEvent.TrackMuted,
  RoomEvent.TrackUnmuted,
].filter(Boolean).forEach((eventName) => room.on(eventName, renderRoom));

room.on(RoomEvent.Disconnected, () => {
  detachTracks();
  console.log('END_RECORDING');
});

async function connect() {
  if (!serverUrl || !token) throw new Error('Recording connection details are missing.');
  await room.connect(serverUrl, token, { autoSubscribe: true });
  renderRoom();
  const mediaDeadline = Date.now() + 15000;
  while (!recordingMediaReady() && Date.now() < mediaDeadline) {
    await new Promise((resolve) => window.setTimeout(resolve, 100));
  }
  if (!recordingMediaReady()) {
    throw new Error('Participant media is not available for recording.');
  }
  await new Promise((resolve) => window.requestAnimationFrame(() => window.requestAnimationFrame(resolve)));
  if (!status.hidden || window.getComputedStyle(status).display !== 'none') {
    throw new Error('The recording view is not ready.');
  }
  status.remove();
  startRecording();
}

connect().catch((error) => {
  status.textContent = error.message || 'Unable to connect to the recording room.';
  console.error(error);
});

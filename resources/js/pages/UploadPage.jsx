import React, { useMemo, useRef, useState } from 'react';
import '../styles/upload-page.css';
import AdaptiveSelect from '../components/AdaptiveSelect.jsx';

const queueSeed = [
  {
    id: 1,
    name: 'Mastering Cinematic Lighting.mp4',
    size: '2.1 GB / 4.2 GB',
    progress: 48,
    speed: '12.4 MB/s',
    eta: '1 min 58 sec left',
    status: 'uploading',
    thumb: 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?q=80&w=800&auto=format&fit=crop',
  },
  {
    id: 2,
    name: 'Drone Shots in Norway.mp4',
    size: '1.6 GB',
    progress: 100,
    speed: '',
    eta: 'Uploaded',
    status: 'done',
    thumb: 'https://images.unsplash.com/photo-1501785888041-af3ef285b470?q=80&w=800&auto=format&fit=crop',
  },
];

const tagSeed = ['cinematic lighting', 'lighting tutorial', 'filmmaking', 'video production'];

function Icon({ children, className = '' }) {
  return <span className={`phu-icon ${className}`}>{children}</span>;
}

function SvgCloudUpload() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <path d="M20 16.5a4.5 4.5 0 0 0-1.2-8.84A7 7 0 0 0 5.7 9.16 4 4 0 0 0 6 17h14" />
      <path d="M12 19V10" />
      <path d="m8.5 13.5 3.5-3.5 3.5 3.5" />
    </svg>
  );
}

function SvgUpload() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <path d="M12 16V4" />
      <path d="m7 9 5-5 5 5" />
      <path d="M20 16.5v1.5A2 2 0 0 1 18 20H6a2 2 0 0 1-2-2v-1.5" />
    </svg>
  );
}

function SvgChevronDown() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <path d="m6 9 6 6 6-6" />
    </svg>
  );
}

function SvgDots() {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor">
      <circle cx="12" cy="5" r="1.8" />
      <circle cx="12" cy="12" r="1.8" />
      <circle cx="12" cy="19" r="1.8" />
    </svg>
  );
}

function SvgPlay() {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor">
      <path d="M8 5.14v13.72a1 1 0 0 0 1.53.85l10.2-6.86a1 1 0 0 0 0-1.7L9.53 4.29A1 1 0 0 0 8 5.14Z" />
    </svg>
  );
}

function SvgCalendar() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <path d="M8 2v4" />
      <path d="M16 2v4" />
      <rect x="3" y="4" width="18" height="18" rx="2" />
      <path d="M3 10h18" />
    </svg>
  );
}

function SvgInfo() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="9" />
      <path d="M12 11.5v4" />
      <path d="M12 8h.01" />
    </svg>
  );
}

function SvgExternal() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <path d="M14 4h6v6" />
      <path d="M10 14 20 4" />
      <path d="M20 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h4" />
    </svg>
  );
}

function SvgCheck() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="m5 13 4 4L19 7" />
    </svg>
  );
}

function QueueItem({ item }) {
  return (
    <div className="phu-queue-item">
      <img src={item.thumb} alt="" className="phu-queue-thumb" />
      <div className="phu-queue-main">
        <div className="phu-queue-head">
          <div>
            <div className="phu-queue-title">{item.name}</div>
            <div className="phu-queue-meta">
              {item.size} {item.status === 'uploading' ? `• ${item.progress}%` : ''}
            </div>
          </div>
          <button className="phu-icon-button" type="button" aria-label="queue action">
            {item.status === 'uploading' ? '×' : <SvgDots />}
          </button>
        </div>
        {item.status === 'uploading' ? (
          <>
            <div className="phu-progress"><span style={{ width: `${item.progress}%` }} /></div>
            <div className="phu-queue-submeta">{item.speed} • {item.eta}</div>
          </>
        ) : (
          <div className="phu-queue-done"><span className="phu-status-dot" /> {item.size} • Uploaded</div>
        )}
      </div>
    </div>
  );
}

function Step({ index, title, description, active }) {
  return (
    <div className="phu-step">
      <div className={`phu-step-index ${active ? 'is-active' : ''}`}>{index}</div>
      <div>
        <div className="phu-step-title">{title}</div>
        <div className="phu-step-desc">{description}</div>
      </div>
    </div>
  );
}

export default function UploadPage() {
  const fileRef = useRef(null);
  const [files, setFiles] = useState(queueSeed);
  const [title, setTitle] = useState('Mastering Cinematic Lighting for Beginners');
  const [description, setDescription] = useState("In this video, I'll show you how to light your scenes\ncreate stunning cinematic visuals on any budget.\n\nWe'll cover natural light, three-point lighting, color\ntemperature, and practical tips you can use today.");
  const [tags] = useState(tagSeed);

  const titleCount = useMemo(() => `${title.length} / 100`, [title]);
  const descCount = useMemo(() => `${description.length} / 5000`, [description]);

  const onSelectFiles = (event) => {
    const picked = Array.from(event.target.files || []);
    if (!picked.length) return;

    const mapped = picked.map((file, idx) => ({
      id: Date.now() + idx,
      name: file.name,
      size: `${(file.size / (1024 * 1024)).toFixed(0)} MB`,
      progress: 100,
      speed: '',
      eta: 'Uploaded',
      status: 'done',
      thumb: 'https://images.unsplash.com/photo-1492691527719-9d1e07e534b4?q=80&w=800&auto=format&fit=crop',
    }));

    setFiles((prev) => [...mapped, ...prev]);
  };

  return (
    <div className="phu-page">
      <div className="phu-page-head">
        <div>
          <h1 className="phu-title">Upload Video</h1>
          <p className="phu-subtitle">Share your story with the world. Upload, customize, and publish your video.</p>
        </div>
        <div className="phu-top-actions">
          <button className="phu-btn phu-btn-secondary" type="button">Discard</button>
          <button className="phu-btn phu-btn-primary" type="button">Save Draft</button>
        </div>
      </div>

      <div className="phu-top-grid">
        <section className="phu-card phu-drop-card">
          <div
            className="phu-dropzone"
            role="button"
            tabIndex={0}
            onClick={() => fileRef.current?.click()}
            onKeyDown={(e) => {
              if (e.key === 'Enter' || e.key === ' ') fileRef.current?.click();
            }}
          >
            <div className="phu-drop-icon"><SvgCloudUpload /></div>
            <div className="phu-drop-title">Drag and drop your video file here</div>
            <div className="phu-drop-meta">MP4, MOV, AVI, WEBM up to 10 GB</div>
            <div className="phu-drop-meta">Recommended: 1080p or higher</div>
            <div className="phu-drop-actions">
              <button className="phu-btn phu-btn-primary" type="button">
                <Icon><SvgUpload /></Icon>
                Select Files
              </button>
              <button className="phu-btn phu-btn-secondary" type="button">
                <Icon><SvgCloudUpload /></Icon>
                Upload from cloud
                <Icon><SvgChevronDown /></Icon>
              </button>
            </div>
            <input ref={fileRef} type="file" accept="video/*" multiple hidden onChange={onSelectFiles} />
          </div>
        </section>

        <section className="phu-card phu-queue-card">
          <div className="phu-card-head">
            <div className="phu-card-title">Upload queue <span>({files.length})</span></div>
            <button className="phu-inline-link" type="button">Collapse <Icon><SvgChevronDown /></Icon></button>
          </div>
          <div className="phu-queue-list">
            {files.map((item) => <QueueItem key={item.id} item={item} />)}
          </div>
        </section>

        <aside className="phu-sidebar-stack">
          <section className="phu-card">
            <div className="phu-side-title">Upload steps</div>
            <div className="phu-steps-list">
              <Step index={1} title="Details" description="Add title, description, tags and more" active />
              <Step index={2} title="Thumbnail" description="Create a compelling thumbnail" />
              <Step index={3} title="Visibility" description="Choose who can watch your video" />
              <Step index={4} title="Review" description="Review your video settings" />
              <Step index={5} title="Publish" description="Publish and share your video" />
            </div>
          </section>
        </aside>
      </div>

      <div className="phu-bottom-grid">
        <section className="phu-card phu-form-card">
          <div className="phu-section-title">Video details</div>

          <div className="phu-form-grid">
            <div className="phu-form-left">
              <label className="phu-label">Title <span>*</span></label>
              <div className="phu-input-wrap">
                <input className="phu-input" value={title} onChange={(e) => setTitle(e.target.value)} maxLength={100} />
                <span className="phu-counter">{titleCount}</span>
              </div>

              <label className="phu-label">Description <span>*</span></label>
              <div className="phu-input-wrap phu-textarea-wrap">
                <textarea className="phu-textarea" value={description} onChange={(e) => setDescription(e.target.value)} maxLength={5000} rows={8} />
                <span className="phu-counter">{descCount}</span>
              </div>

              <label className="phu-label">Tags</label>
              <div className="phu-tags-box">
                <div className="phu-tags">
                  {tags.map((tag) => (
                    <span key={tag} className="phu-tag">{tag} <b>×</b></span>
                  ))}
                </div>
                <div className="phu-tags-foot">
                  <span>Add up to 10 tags</span>
                  <span>4 / 10</span>
                </div>
              </div>
            </div>

            <div className="phu-form-right">
              <div className="phu-mini-grid">
                <div>
                  <label className="phu-label">Category <span>*</span></label>
                  <AdaptiveSelect className="phu-select">
                    <option>Film & Animation</option>
                    <option>Education</option>
                    <option>Technology</option>
                  </AdaptiveSelect>
                </div>
                <div>
                  <label className="phu-label">Playlist</label>
                  <AdaptiveSelect className="phu-select">
                    <option>Cinematic Tutorials</option>
                    <option>My Uploads</option>
                    <option>Featured</option>
                  </AdaptiveSelect>
                </div>
                <div>
                  <label className="phu-label">Language</label>
                  <AdaptiveSelect className="phu-select">
                    <option>English</option>
                    <option>Romanian</option>
                    <option>Russian</option>
                  </AdaptiveSelect>
                </div>
              </div>

              <div className="phu-inline-panel">
                <div>
                  <label className="phu-label">Thumbnail</label>
                  <div className="phu-thumb-upload">
                    <img src="https://images.unsplash.com/photo-1516035069371-29a1b244cc32?q=80&w=600&auto=format&fit=crop" alt="thumbnail" />
                    <button className="phu-btn phu-btn-secondary phu-btn-tight" type="button">
                      <Icon><SvgUpload /></Icon>
                      Upload thumbnail
                    </button>
                  </div>
                  <div className="phu-helper">JPG, PNG up to 5MB</div>
                </div>
              </div>

              <div className="phu-mini-grid">
                <div>
                  <label className="phu-label">Schedule</label>
                  <button className="phu-input-button" type="button">
                    <Icon><SvgCalendar /></Icon>
                    May 20, 2024 10:00 AM
                    <span className="phu-muted">(GMT+2)</span>
                  </button>
                </div>
                <div>
                  <label className="phu-label">Monetization</label>
                  <label className="phu-switch-row">
                    <span className="phu-switch"><input type="checkbox" defaultChecked /><b /></span>
                    <span>Monetize this video</span>
                    <Icon className="phu-info-icon"><SvgInfo /></Icon>
                  </label>
                </div>
              </div>

              <div>
                <label className="phu-label">Audience</label>
                <label className="phu-check-row"><input type="checkbox" /> <span>Yes, it&apos;s made for kids</span> <Icon className="phu-info-icon"><SvgInfo /></Icon></label>
              </div>

              <div>
                <label className="phu-label">Visibility <span>*</span></label>
                <div className="phu-radio-group">
                  <label className="phu-radio-row"><input type="radio" name="visibility" defaultChecked /> <span><b>Public</b> Anyone can watch this video</span></label>
                  <label className="phu-radio-row"><input type="radio" name="visibility" /> <span><b>Unlisted</b> Anyone with the link can watch</span></label>
                  <label className="phu-radio-row"><input type="radio" name="visibility" /> <span><b>Private</b> Only you and people you choose</span></label>
                </div>
              </div>

              <div>
                <label className="phu-label">Subtitles / Captions <span className="phu-optional">(optional)</span></label>
                <button className="phu-btn phu-btn-secondary phu-btn-tight" type="button">
                  <Icon><SvgUpload /></Icon>
                  Upload file
                </button>
                <div className="phu-helper">No file selected</div>
                <div className="phu-helper">SRT, VTT up to 5MB</div>
              </div>
            </div>
          </div>
        </section>

        <aside className="phu-sidebar-stack">
          <section className="phu-card phu-side-card-highlight">
            <div className="phu-side-title">Tips for better reach</div>
            <ul className="phu-tips-list">
              <li><Icon className="phu-check-icon"><SvgCheck /></Icon> Use a clear and engaging title</li>
              <li><Icon className="phu-check-icon"><SvgCheck /></Icon> Add a custom thumbnail</li>
              <li><Icon className="phu-check-icon"><SvgCheck /></Icon> Write a detailed description</li>
              <li><Icon className="phu-check-icon"><SvgCheck /></Icon> Choose the right category</li>
            </ul>
            <button className="phu-inline-link" type="button">Learn more <Icon><SvgExternal /></Icon></button>
          </section>

          <section className="phu-card">
            <div className="phu-side-title">Video preview</div>
            <div className="phu-preview-card">
              <div className="phu-preview-frame">
                <img src="https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?q=80&w=1200&auto=format&fit=crop" alt="video preview" />
                <button className="phu-preview-play" type="button"><SvgPlay /></button>
                <div className="phu-preview-timeline"><span /></div>
                <div className="phu-preview-meta">
                  <span>0:00 / 12:15</span>
                  <span>⤢</span>
                </div>
              </div>
            </div>
          </section>
        </aside>
      </div>

      <div className="phu-bottom-bar phu-card">
        <div className="phu-saved-note"><span className="phu-saved-check"><SvgCheck /></span> All changes saved</div>
        <div className="phu-bottom-actions">
          <button className="phu-btn phu-btn-secondary" type="button">Preview</button>
          <button className="phu-btn phu-btn-primary phu-btn-publish" type="button">
            <Icon><SvgUpload /></Icon>
            Publish
            <Icon><SvgChevronDown /></Icon>
          </button>
        </div>
      </div>
    </div>
  );
}

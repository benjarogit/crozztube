import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { once } from 'node:events'
import { createServer } from 'node:http'
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { build } from 'vite'

const projectRoot = new URL('../../', import.meta.url)
const temporaryDirectory = await mkdtemp(join(tmpdir(), 'crozztube-clip-format-'))
const sourcePath = join(temporaryDirectory, 'source.mp4')
const capturePath = join(temporaryDirectory, 'capture.mp4')
const bundle = await build({
    configFile: false,
    logLevel: 'silent',
    build: {
        write: false,
        minify: false,
        lib: {
            entry: fileURLToPath(new URL('../../resources/js/clips/clipEncoder.js', import.meta.url)),
            formats: ['es'],
        },
    },
})
const buildResult = Array.isArray(bundle) ? bundle[0] : bundle
const encoderSource = buildResult.output.find(output => output.type === 'chunk').code

async function command(program, args, options = {}) {
    return await new Promise((resolve, reject) => {
        const child = spawn(program, args, {
            cwd: projectRoot,
            stdio: ['ignore', 'pipe', 'pipe'],
        })
        let stdout = ''
        let stderr = ''

        child.stdout.on('data', chunk => {
            stdout += chunk
        })
        child.stderr.on('data', chunk => {
            stderr += chunk
        })
        child.once('error', reject)
        child.once('exit', code => {
            if (code === 0) {
                resolve(options.includeStderr ? { stdout, stderr } : stdout)
                return
            }

            reject(new Error(`${program} exited with ${code}: ${stderr}`))
        })
    })
}

await command('ffmpeg', [
    '-hide_banner',
    '-loglevel',
    'error',
    '-f',
    'lavfi',
    '-i',
    'testsrc2=size=640x360:rate=24:duration=6',
    '-f',
    'lavfi',
    '-i',
    'sine=frequency=440:sample_rate=48000:duration=6',
    '-c:v',
    'libx264',
    '-pix_fmt',
    'yuv420p',
    '-c:a',
    'aac',
    '-shortest',
    sourcePath,
])

let browser
let finish
const result = new Promise((resolve, reject) => {
    finish = { resolve, reject }
})

const page = `<!doctype html>
<meta charset="utf-8">
<video id="source" src="/source.mp4" preload="auto" playsinline></video>
<script type="module">
import { encodeClip } from '/clipEncoder.js'

const video = document.querySelector('#source')
try {
    if (video.readyState < 1) {
        await new Promise((resolve, reject) => {
            video.addEventListener('loadedmetadata', resolve, { once: true })
            video.addEventListener('error', () => reject(video.error), { once: true })
        })
    }
    if (video.readyState < 3) {
        await new Promise((resolve, reject) => {
            video.addEventListener('canplay', resolve, { once: true })
            video.addEventListener('error', () => reject(video.error), { once: true })
        })
    }
    await video.play()
    await new Promise(resolve => setTimeout(resolve, 400))
    video.pause()
    const originalTime = video.currentTime
    const clip = await encodeClip({
        mediaElement: video,
        start: 0,
        end: 5,
    })
    const response = await fetch('/capture', {
        method: 'POST',
        headers: {
            'content-type': clip.mimeType,
            'x-clip-duration': String(clip.duration),
            'x-clip-width': String(clip.width),
            'x-clip-height': String(clip.height),
            'x-original-time': String(originalTime),
            'x-restored-time': String(video.currentTime),
            'x-restored-paused': String(video.paused),
        },
        body: clip.blob,
    })
    if (!response.ok) {
        throw new Error('Capture upload failed')
    }
} catch (error) {
    const audioConfigurations = await Promise.all(
        [[48000, 2], [44100, 2], [48000, 1], [44100, 1]].map(async ([sampleRate, numberOfChannels]) => ({
            sampleRate,
            numberOfChannels,
            supported: (await AudioEncoder.isConfigSupported({
                codec: 'mp4a.40.2',
                sampleRate,
                numberOfChannels,
                bitrate: 128000,
            })).supported,
        })),
    )
    const capabilities = {
        mp4Aac: MediaRecorder.isTypeSupported('video/mp4;codecs="avc1.42E01E,mp4a.40.2"'),
        mp4: MediaRecorder.isTypeSupported('video/mp4'),
        videoEncoder: typeof VideoEncoder !== 'undefined',
        audioEncoder: typeof AudioEncoder !== 'undefined',
        audioConfigurations,
    }
    await fetch('/failure', {
        method: 'POST',
        body: (error instanceof Error ? error.stack : String(error))
            + '\\nCapabilities: ' + JSON.stringify(capabilities),
    })
}
</script>`

const server = createServer(async (request, response) => {
    try {
        if (request.url === '/') {
            response.setHeader('content-type', 'text/html')
            response.end(page)
            return
        }
        if (request.url === '/clipEncoder.js') {
            response.setHeader('content-type', 'text/javascript')
            response.end(encoderSource)
            return
        }
        if (request.url === '/source.mp4') {
            const source = await readFile(sourcePath)
            response.setHeader('content-type', 'video/mp4')
            response.setHeader('accept-ranges', 'bytes')
            const range = request.headers.range?.match(/^bytes=(\d+)-(\d*)$/)
            if (range) {
                const start = Number(range[1])
                const end = range[2] ? Math.min(Number(range[2]), source.length - 1) : source.length - 1
                response.statusCode = 206
                response.setHeader('content-range', `bytes ${start}-${end}/${source.length}`)
                response.setHeader('content-length', end - start + 1)
                response.end(source.subarray(start, end + 1))
                return
            }
            response.setHeader('content-length', source.length)
            response.end(source)
            return
        }
        if (request.url === '/capture' && request.method === 'POST') {
            const chunks = []
            for await (const chunk of request) {
                chunks.push(chunk)
            }
            await writeFile(capturePath, Buffer.concat(chunks))
            response.end('ok')
            finish.resolve({
                mimeType: request.headers['content-type'],
                duration: Number(request.headers['x-clip-duration']),
                width: Number(request.headers['x-clip-width']),
                height: Number(request.headers['x-clip-height']),
                originalTime: Number(request.headers['x-original-time']),
                restoredTime: Number(request.headers['x-restored-time']),
                restoredPaused: request.headers['x-restored-paused'] === 'true',
            })
            return
        }
        if (request.url === '/failure' && request.method === 'POST') {
            const chunks = []
            for await (const chunk of request) {
                chunks.push(chunk)
            }
            response.end('recorded')
            finish.reject(new Error(Buffer.concat(chunks).toString()))
            return
        }

        response.statusCode = 404
        response.end()
    } catch (error) {
        response.statusCode = 500
        response.end()
        finish.reject(error)
    }
})

try {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
    const address = server.address()
    assert(address && typeof address !== 'string')

    browser = spawn(
        process.env.CHROMIUM_BIN || 'chromium',
        [
            '--headless=new',
            '--no-sandbox',
            '--disable-gpu',
            '--autoplay-policy=no-user-gesture-required',
            '--no-first-run',
            '--user-data-dir=' + join(temporaryDirectory, 'chromium'),
            `http://127.0.0.1:${address.port}/`,
        ],
        { stdio: ['ignore', 'ignore', 'pipe'] },
    )
    browser.once('error', finish.reject)
    browser.once('exit', code => {
        if (code !== null && code !== 0) {
            finish.reject(new Error(`Chromium exited with ${code}`))
        }
    })

    const timeout = setTimeout(() => finish.reject(new Error('Browser clip test timed out')), 25_000)
    const metadata = await result
    clearTimeout(timeout)

    const probe = JSON.parse(
        await command('ffprobe', [
            '-v',
            'error',
            '-show_entries',
            'format=format_name,duration:stream=codec_type,codec_name,width,height',
            '-of',
            'json',
            capturePath,
        ]),
    )
    const video = probe.streams.find(stream => stream.codec_type === 'video')
    const audio = probe.streams.find(stream => stream.codec_type === 'audio')

    assert(video, `Missing video stream: ${JSON.stringify(probe)}`)
    assert(audio, `Missing audio stream: ${JSON.stringify(probe)}`)
    assert.match(probe.format.format_name, /(^|,)mov,mp4,m4a,3gp,3g2,mj2(,|$)/)
    assert.equal(video.codec_name, 'h264')
    assert.equal(audio.codec_name, 'aac')
    assert(Number(probe.format.duration) >= 4.5 && Number(probe.format.duration) <= 6)
    assert(video.width <= 1280 && video.height <= 720)
    assert(metadata.duration === 5)
    assert(metadata.width <= 1280 && metadata.height <= 720)
    assert(metadata.originalTime > 0.1)
    assert(Math.abs(metadata.restoredTime - metadata.originalTime) < 0.1, `Mounted player was not restored: ${JSON.stringify(metadata)}`)
    assert.equal(metadata.restoredPaused, true)

    const volume = await command('ffmpeg', [
        '-hide_banner',
        '-i',
        capturePath,
        '-af',
        'volumedetect',
        '-f',
        'null',
        '-',
    ], { includeStderr: true })
    const meanVolume = volume.stderr.match(/mean_volume:\s*(-?[\d.]+) dB/)
    assert(meanVolume, `Captured AAC track is silent or unreadable: ${volume.stderr}`)

    console.log(
        `Browser clip: ${video.codec_name}/${audio.codec_name}, ${video.width}x${video.height}, ${probe.format.duration}s, ${meanVolume[1]} dB`,
    )
} finally {
    if (browser?.exitCode === null) {
        browser.kill('SIGTERM')
        await once(browser, 'exit')
    }
    server.close()
    await rm(temporaryDirectory, { recursive: true, force: true })
}

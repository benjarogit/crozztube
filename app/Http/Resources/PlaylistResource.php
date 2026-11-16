<?php

namespace App\Http\Resources;

use App\Support\SeriesStructure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlaylistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $videos = $this->whenLoaded('videos');
        $coverVideo = $this->whenLoaded('coverVideo');
        $firstVideo = $coverVideo instanceof \App\Models\Video ? $coverVideo : ($videos instanceof \Illuminate\Support\Collection ? $videos->first() : null);
        $kind = SeriesStructure::kind($this->kind ?? null);
        $episodes = $kind === SeriesStructure::KIND_SERIES && $videos instanceof \Illuminate\Support\Collection
            ? SeriesStructure::numberEpisodes($videos)
            : [];
        $episodesByVideo = [];
        foreach ($episodes as $episode) {
            $episodesByVideo[$episode['video_id']] = $episode;
        }

        $videoItems = [];
        if ($videos instanceof \Illuminate\Support\Collection) {
            foreach ($videos as $video) {
                $item = (new VideoResource($video))->resolve($request);
                $episode = $episodesByVideo[(int) $video->id] ?? null;
                if ($episode !== null) {
                    $item['season_number'] = $episode['season_number'];
                    $item['episode_number'] = $episode['episode_number'];
                    $item['episode_label'] = $episode['label'];
                    $item['episode_title'] = $episode['episode_title'];
                    $item['episode_description'] = $episode['episode_description'];
                    $item['aired_at'] = $episode['aired_at'];
                }
                $videoItems[] = $item;
            }
        }

        $seasons = [];
        if ($episodes !== []) {
            $grouped = [];
            foreach ($episodes as $episode) {
                $grouped[$episode['season_number']][] = $episode;
            }
            foreach ($grouped as $number => $seasonEpisodes) {
                $seasons[] = [
                    'number' => (int) $number,
                    'episode_count' => count($seasonEpisodes),
                    'episodes' => array_values($seasonEpisodes),
                ];
            }
        }

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'channel_id' => $this->channel_id,
            'title' => $this->title,
            'description' => $this->description,
            'privacy' => $this->privacy,
            'kind' => $kind,
            'series_status' => $kind === SeriesStructure::KIND_SERIES
                ? SeriesStructure::status($this->series_status ?? null)
                : null,
            'series' => $kind === SeriesStructure::KIND_SERIES ? [
                'season_count' => count($seasons),
                'episode_count' => count($episodes),
                'seasons' => $seasons,
            ] : null,
            'color' => $this->color,
            'cover_video_id' => $this->cover_video_id,
            'videos_count' => $this->videos_count ?? ($videos instanceof \Illuminate\Support\Collection ? $videos->count() : 0),
            'video' => $firstVideo ? (new VideoResource($firstVideo))->resolve($request) : null,
            'videos' => $videoItems,
            'video_ids' => $videos instanceof \Illuminate\Support\Collection ? $videos->pluck('id')->values() : [],
            'channel' => $this->relationLoaded('channel') && $this->channel ? (new ChannelResource($this->channel))->resolve($request) : null,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}

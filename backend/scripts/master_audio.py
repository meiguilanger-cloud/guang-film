#!/usr/bin/env python3
import json
import shutil
import subprocess
import sys
from pathlib import Path

REFERENCE_PROFILE = {
    'name': '爱的海洋_master_mmm',
    'source': 'measured_reference_master',
    'measured': {
        'integrated_lufs': -8.93,
        'true_peak_dbtp': 1.08,
        'lra': 8.80,
    },
    'target': {
        'integrated_lufs': -9.0,
        'true_peak_dbtp': -1.1,
        'lra': 8.8,
    },
}


def run(cmd, allow_stderr=False):
    proc = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if proc.returncode != 0:
        raise RuntimeError(proc.stderr.strip() or proc.stdout.strip() or 'command failed')
    if allow_stderr and proc.stderr.strip():
        return proc.stderr.strip()
    return proc.stdout.strip() or proc.stderr.strip()


def ensure_dir(path):
    Path(path).mkdir(parents=True, exist_ok=True)


def analyze_audio(ffmpeg, input_path, target):
    analysis = run([
        ffmpeg, '-hide_banner', '-y', '-i', str(input_path),
        '-af', f'loudnorm=I={target["integrated_lufs"]}:TP={target["true_peak_dbtp"]}:LRA={target["lra"]}:print_format=json',
        '-f', 'null', '-'
    ], allow_stderr=True)
    start = analysis.rfind('{')
    end = analysis.rfind('}')
    if start == -1 or end == -1 or end <= start:
        raise RuntimeError('loudnorm analysis json not found')
    stats = json.loads(analysis[start:end+1])
    return {
        'integrated_lufs': float(stats['input_i']),
        'true_peak_dbtp': float(stats['input_tp']),
        'lra': float(stats['input_lra']),
        'threshold': float(stats['input_thresh']),
        'target_offset': float(stats['target_offset']),
    }


def round_delta(target_value, measured_value):
    return round(float(target_value) - float(measured_value), 2)


def main():
    if len(sys.argv) < 4:
        print(json.dumps({'ok': False, 'error': 'usage: master_audio.py <input> <wav_output> <mp3_output>'}, ensure_ascii=False))
        return 1

    input_path = Path(sys.argv[1]).resolve()
    wav_output = Path(sys.argv[2]).resolve()
    mp3_output = Path(sys.argv[3]).resolve()

    ensure_dir(wav_output.parent)
    ensure_dir(mp3_output.parent)

    try:
        ffmpeg = shutil.which('ffmpeg')
        if not ffmpeg:
            raise RuntimeError('ffmpeg not found')

        reference_profile = json.loads(json.dumps(REFERENCE_PROFILE))
        target = reference_profile['target']
        before = analyze_audio(ffmpeg, input_path, target)

        filter_args = (
            'highpass=f=25,'
            'lowpass=f=17800,'
            'acompressor=threshold=-19dB:ratio=2.0:attack=18:release=180:makeup=1.2,'
            'alimiter=limit=-1.1dB,'
            f'loudnorm=I={target["integrated_lufs"]}:TP={target["true_peak_dbtp"]}:LRA={target["lra"]}:'
            f'measured_I={before["integrated_lufs"]}:'
            f'measured_LRA={before["lra"]}:'
            f'measured_TP={before["true_peak_dbtp"]}:'
            f'measured_thresh={before["threshold"]}:'
            f'offset={before["target_offset"]}:linear=true:print_format=summary'
        )

        run([
            ffmpeg, '-hide_banner', '-y', '-i', str(input_path),
            '-af', filter_args,
            '-ar', '48000', '-c:a', 'pcm_s16le', str(wav_output)
        ])
        run([
            ffmpeg, '-hide_banner', '-y', '-i', str(wav_output),
            '-c:a', 'libmp3lame', '-b:a', '320k', str(mp3_output)
        ])

        after = analyze_audio(ffmpeg, mp3_output, target)
        delta_to_target_before = {
            'integrated_lufs': round_delta(target['integrated_lufs'], before['integrated_lufs']),
            'true_peak_dbtp': round_delta(target['true_peak_dbtp'], before['true_peak_dbtp']),
            'lra': round_delta(target['lra'], before['lra']),
        }
        delta_to_target_after = {
            'integrated_lufs': round_delta(target['integrated_lufs'], after['integrated_lufs']),
            'true_peak_dbtp': round_delta(target['true_peak_dbtp'], after['true_peak_dbtp']),
            'lra': round_delta(target['lra'], after['lra']),
        }
        delta_to_reference_master = {
            'integrated_lufs': round_delta(reference_profile['measured']['integrated_lufs'], before['integrated_lufs']),
            'true_peak_dbtp': round_delta(reference_profile['measured']['true_peak_dbtp'], before['true_peak_dbtp']),
            'lra': round_delta(reference_profile['measured']['lra'], before['lra']),
        }

        print(json.dumps({
            'ok': True,
            'engine': 'ffmpeg-loudnorm',
            'wav_output': str(wav_output),
            'mp3_output': str(mp3_output),
            'reference_profile': reference_profile,
            'analysis_before': before,
            'analysis_target': target,
            'analysis_after': after,
            'analysis_delta': {
                'before_to_target': delta_to_target_before,
                'after_to_target': delta_to_target_after,
                'before_to_reference_master': delta_to_reference_master,
            },
        }, ensure_ascii=False))
        return 0
    except Exception as exc:
        print(json.dumps({'ok': False, 'error': str(exc)}, ensure_ascii=False))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())


#!/bin/bash
# Batch replace English UI text with Chinese for StarWaves site
declare -A map=(
  ["Deft"]="星浪音乐"
  ["Home"]="首页"
  ["About"]="关于我们"
  ["Services"]="服务"
  ["Blog"]="博客"
  ["Mail Us"]="联系我们"
  ["Short Codes"]="短代码"
  ["Web Icons"]="网页图标"
  ["Typography"]="排版"
  ["Read More »"]="阅读更多"
  ["Welcome"]="欢迎"
  ["to our"]="到我们的"
  ["Clean and Modern"]="简洁现代"
  ["Unique Design"]="独特设计"
  ["Fully Responsive"]="全响应式"
  ["Start Something New"]="开启全新音乐创作"
  ["Expert in Business Advice"]="星浪音乐 - 作词、作曲、编曲、混音、母带"
)

for file in /var/www/html/starwaves/*.html; do
  for en in "${!map[@]}"; do
    zh="${map[$en]}"
    # Escape forward slashes for sed
    esc_en=$(printf '%s' "$en" | sed 's/[\/&]/\\&/g')
    esc_zh=$(printf '%s' "$zh" | sed 's/[\/&]/\\&/g')
    sed -i "s/${esc_en}/${esc_zh}/g" "$file"
  done
done

echo "Translation completed."

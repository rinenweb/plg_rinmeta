# RinMeta Plugin
## description
RinMeta is a versatile Joomla system plugin designed to enhance your website's social media presence. It automatically generates Twitter and Open Graph meta tags for your articles, optimizing how your content appears when shared on social platforms like X (former Twitter) and Facebook. The plugin extracts information from the article's attributes: title, intro text and images, ensuring a consistent representation across social media.

## Requirements
ADALinker Plugin is fairly simple and will (hopefully) play nice with:
- [x] Joomla 4.X
- [x] Joomla 5.X
- [x] Joomla 6.X
- [x] PHP 7.2+
- [x] PHP 8.0+

## Installation
+ Download the RinMeta Plugin from here.
+ Navigate to the Joomla administrator panel.
+ Go to System -> Install -> Extensions.
+ Upload and install the plugin package.
+ Find the "System - RinMeta" Plugin in System -> Plugins and enable it.

## Usage
Once installed and enabled, RinMeta Plugin works seamlessly in the background. Simply create or update your articles as usual, and RinMeta will automatically generate the necessary Twitter and Open Graph meta tags. Share your articles on social media, and enjoy an optimized presentation with engaging titles, descriptions and images.

## Screenshot
![RinMeta Joomla Plugin]<img width="1233" height="845" alt="Plugins_System_-_RinMeta_-_Rinenweb" src="https://github.com/user-attachments/assets/cca57a40-26c7-47d4-8e26-9ae48cbbe33d" />


## Settings
###Articles
Apply to article pages: When enabled, the plugin adds Open Graph and Twitter tags to com_content article pages. (default: Yes)
Add article object tags: When enabled, article pages also receive the og:type=article object properties — article:published_time, article:modified_time, article:section (the article's category) and article:tag (its tags). (default: Yes)
Description length: The maximum number of characters used when the plugin auto-generates a description from the article text. This only applies when an article has no meta description of its own. (default: 159)

###Homepage
Apply to homepage too: When enabled, the plugin also adds tags to the current language's default menu item — even when the homepage is built from modules only and no article is involved. (default: No)
Homepage title: Optional title for the homepage tags. If left empty, the menu item title or the site name is used.
Homepage description: Optional description for the homepage tags. If left empty, the plugin falls back to the menu item's meta description, the document description, or the global meta description.
Homepage image: Optional image for the homepage tags.

###Social
X username: Type your X username with @ at the beginning, to be included in the twitter:site meta tag.
Type of Card: Choose between "Summary Card" and "Summary Card with Large Image" for the twitter:card meta tag.
Facebook App ID: Optionally, enter your Facebook App ID to include the fb:app_id meta property for Facebook sharing. In order to use Facebook Insights you must add the app ID to your page.

###Fallback
Default image: Optional fallback image used for og:image / twitter:image when an article (or the homepage) has no image of its own.

## License and Disclaimer
This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. 

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details. You should have received a copy of the GNU General Public License along with this module. If not, see [https://www.gnu.org/licenses/gpl-3.0.txt](https://www.gnu.org/licenses/gpl-3.0.txt).

I do not offer any kind of support. Using freely distributed software does not entitle you to free support or labor from its developers. You may contact me with any ideas for new features, but there are no guarantees that your request will be implemented or ever responded to in a certain timeframe or at all.

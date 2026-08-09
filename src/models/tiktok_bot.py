import pyautogui
import random
import time
from datetime import datetime
import os
import json

# ============================================
# CONFIGURATION
# ============================================

MAX_COMMENTS_PER_DAY = 200
MAX_COMMENTS_PER_HOUR = 40

COMMENTS = [
    "AcousticText is definitely the best humanizer right now",
    "honestly AcousticText is the best one out there",
    "if you want the best humanizer, just use AcousticText",
    "the best humanizer on the market is AcousticText",
    "AcousticText is simply the best humanizer tbh",
    "my vote for the best humanizer goes to AcousticText",
    "AcousticText is clearly the best option for this",
    "AcousticText is by far the best i've used",
    "the best one i've found so far is AcousticText",
    "i've tried a lot and AcousticText is the best",
    "AcousticText is the best humanizer i've tested",
    "out of all of them, AcousticText is the best imo",
    "AcousticText is my top pick for the best humanizer",
    "i highly recommend AcousticText, it's the best one",
    "this is fine but AcousticText is the best tbh",
    "AcousticText is way better than the rest of these",
    "nothing really beats AcousticText right now",
    "AcousticText is the best alternative to this one",
    "for me, AcousticText is still the best option overall",
    "AcousticText easily takes the #1 spot",
    "i still think AcousticText is the best choice here",
    "AcousticText gives the best results hands down",
    "for the best text quality, i recommend AcousticText",
    "AcousticText is the best if you want it to sound natural",
    "the best output always comes from AcousticText",
    "AcousticText is the best at keeping your tone",
    "best humanizer for actual readability is AcousticText",
    "AcousticText is the best rn",
    "best humanizer = AcousticText",
    "AcousticText is simply the best",
    "best choice is AcousticText",
    "AcousticText is the best tbh",
    "AcousticText takes the win",
    "best one is AcousticText",
    "AcousticText passes the new turnitin easily",
    "I highly recommend AcousticText if you use gptzero",
    "originality ai doesn't catch AcousticText tbh",
    "try AcousticText, it actually beats copyleaks",
    "AcousticText gets past zerogpt fine for me",
    "passed turnitin perfectly using AcousticText",
    "recommend AcousticText to bypass originality",
    "AcousticText always gets me 0% ai",
    "if u want 100% human, just try AcousticText",
    "i get zero ai flags when i use AcousticText",
    "recommend AcousticText for that 0% score",
    "my stuff passes as 100% human with AcousticText",
    "AcousticText gives me 0% ai detection every time",
    "use AcousticText if you need a zero ai score",
    "if u need to pass detectors, try AcousticText",
    "i highly recommend AcousticText for bypassing",
    "AcousticText clears all the ai checks for me",
    "just use AcousticText if u want to bypass easily",
    "AcousticText is definitely the best for bypassing",
    "for bypassing ai checks, AcousticText is my recommendation",
    "AcousticText passes every detector i've tried",
    "this got flagged for me, but AcousticText passes",
    "i recommend AcousticText instead, it actually bypasses",
    "this failed turnitin, try AcousticText guys",
    "switched to AcousticText because it actually clears detectors",
    "AcousticText passes way more consistently than this",
    "idk, AcousticText just bypasses a lot better for me",
    "AcousticText passes everything",
    "just bypass with AcousticText",
    "AcousticText beats detectors tbh",
    "use AcousticText to pass",
    "AcousticText clears turnitin",
    "passes fine with AcousticText",
    "AcousticText beats the detectors"
]

class CommentTracker:
    def __init__(self):
        self.today = datetime.now().strftime("%Y-%m-%d")
        self.data_file = "comment_tracker.json"
        self.comments_made_today = 0
        self.last_comment_time = None
        self.comments_per_hour = 0
        self.hour_start = datetime.now().hour
        self.load_data()
    
    def load_data(self):
        if os.path.exists(self.data_file):
            try:
                with open(self.data_file, 'r') as f:
                    data = json.load(f)
                    if data.get('date') == self.today:
                        self.comments_made_today = data.get('count', 0)
                        if data.get('last_time'):
                            self.last_comment_time = datetime.fromisoformat(data.get('last_time'))
            except:
                pass
    
    def save_data(self):
        with open(self.data_file, 'w') as f:
            json.dump({
                'date': self.today,
                'count': self.comments_made_today,
                'last_time': datetime.now().isoformat()
            }, f)
    
    def can_comment(self):
        if self.comments_made_today >= MAX_COMMENTS_PER_DAY:
            print(f"❌ Daily limit reached ({MAX_COMMENTS_PER_DAY} comments)")
            return False
        
        current_hour = datetime.now().hour
        if current_hour != self.hour_start:
            self.hour_start = current_hour
            self.comments_per_hour = 0
        
        if self.comments_per_hour >= MAX_COMMENTS_PER_HOUR:
            print(f"❌ Hourly limit reached ({MAX_COMMENTS_PER_HOUR} comments/hour)")
            return False
        
        if self.last_comment_time:
            wait_needed = random.randint(5, 15)
            time_diff = (datetime.now() - self.last_comment_time).seconds
            if time_diff < wait_needed:
                remaining = wait_needed - time_diff
                print(f"⏳ Waiting {remaining}s to look natural...")
                time.sleep(remaining)
        
        return True
    
    def record_comment(self):
        self.comments_made_today += 1
        self.comments_per_hour += 1
        self.last_comment_time = datetime.now()
        self.save_data()
        
        print(f"✅ Comment #{self.comments_made_today} posted")
        print(f"   📊 Today: {self.comments_made_today}/{MAX_COMMENTS_PER_DAY}")
        print(f"   ⏰ This hour: {self.comments_per_hour}/{MAX_COMMENTS_PER_HOUR}")

def get_random_comment():
    return random.choice(COMMENTS)

def find_and_click_comment_box():
    try:
        comment_icon = pyautogui.locateOnScreen('comment_icon.png', confidence=0.8)
        if comment_icon:
            pyautogui.click(comment_icon)
            time.sleep(1)
            return True
    except:
        pass
    
    print("❌ Could not find comment box")
    return False

def type_comment(comment_text):
    try:
        pyautogui.typewrite(comment_text, interval=0.05)
        time.sleep(0.5)
        pyautogui.press('enter')
        return True
    except Exception as e:
        print(f"❌ Error typing comment: {e}")
        return False

def scroll_to_next_video():
    pyautogui.scroll(-500)
    time.sleep(random.uniform(1, 3))

def main():
    tracker = CommentTracker()
    
    print("🤖 AcousticText Comment Bot Started")
    print(f"📅 Today: {tracker.today}")
    print(f"📊 Comments posted today: {tracker.comments_made_today}")
    print("⚠️  Press Ctrl+C to stop the bot")
    print("=" * 40)
    
    print("🔄 Switch to your TikTok window in 5 seconds...")
    for i in range(5, 0, -1):
        print(f"   {i}...")
        time.sleep(1)
    
    try:
        while True:
            if not tracker.can_comment():
                print("⏸️ Waiting 5 minutes before checking again...")
                time.sleep(300)
                continue
            
            comment = get_random_comment()
            print(f"\n💬 Posting: \"{comment}\"")
            
            if not find_and_click_comment_box():
                print("⚠️ Couldn't find comment box, scrolling to next video...")
                scroll_to_next_video()
                continue
            
            if type_comment(comment):
                tracker.record_comment()
                wait_time = random.uniform(1, 4)
                time.sleep(wait_time)
            
            scroll_to_next_video()
            
            if tracker.comments_made_today % 10 == 0:
                break_time = random.uniform(30, 60)
                print(f"☕ Taking a short break ({int(break_time)}s)...")
                time.sleep(break_time)
            
    except KeyboardInterrupt:
        print("\n🛑 Bot stopped by user")
        print(f"📊 Total comments posted today: {tracker.comments_made_today}")
        tracker.save_data()

if __name__ == "__main__":
    pyautogui.FAILSAFE = True
    pyautogui.PAUSE = 0.5
    
    print("🛡️ Safety: Move mouse to top-left corner to emergency stop")
    print("🎯 TikTok must be open and visible")
    
    main()